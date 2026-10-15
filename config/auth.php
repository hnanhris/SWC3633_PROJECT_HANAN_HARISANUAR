
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Return a JSON error response and stop execution.
 */
function authError(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json');

    echo json_encode([
        'success' => false,
        'message' => $message
    ]);

    exit;
}

/**
 * Retrieve the Authorization header.
 * Supports common Apache and PHP configurations.
 */
function getAuthorizationHeader(): string
{
    // Method 1: Standard PHP server variable
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // Method 2: Redirected Authorization header
    if ($authHeader === '') {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }

    // Method 3: Apache request headers
    if ($authHeader === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();

        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $authHeader = $value;
                break;
            }
        }
    }

    // Method 4: General HTTP headers
    if ($authHeader === '' && function_exists('getallheaders')) {
        $headers = getallheaders();

        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $authHeader = $value;
                break;
            }
        }
    }

    return trim($authHeader);
}

/**
 * Verify JWT and optionally restrict access by role.
 *
 * Example:
 * requireAuth(['admin']);
 * requireAuth(['admin', 'organiser']);
 */
function requireAuth(array $allowedRoles = []): array
{
    $config = require __DIR__ . '/jwt_secret.php';

    if (
        !isset($config['secret'], $config['issuer']) ||
        !is_string($config['secret']) ||
        strlen($config['secret']) < 32
    ) {
        authError(500, 'JWT configuration error');
    }

    $authHeader = getAuthorizationHeader();

    // Verify Bearer token format
    if (!preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $matches)) {
        authError(401, 'Missing or invalid Bearer token');
    }

    $token = $matches[1];

    try {
        // Verify signature and token expiration
        $decoded = JWT::decode(
            $token,
            new Key($config['secret'], 'HS256')
        );

    } catch (Throwable $e) {
        authError(401, 'Invalid or expired token');
    }

    // Verify token claims
    if (
        ($decoded->iss ?? '') !== $config['issuer'] ||
        !isset($decoded->sub, $decoded->role) ||
        !ctype_digit((string) $decoded->sub) ||
        (int) $decoded->sub <= 0 ||
        !in_array($decoded->role, ['admin', 'organiser', 'customer'], true)
    ) {
        authError(401, 'Invalid token claims');
    }

    // Verify role permission
    if (
        !empty($allowedRoles) &&
        !in_array($decoded->role, $allowedRoles, true)
    ) {
        authError(403, 'Access denied for this role');
    }

    // Return authenticated user information
    return [
        'user_id' => (int) $decoded->sub,
        'role' => $decoded->role
    ];
}
