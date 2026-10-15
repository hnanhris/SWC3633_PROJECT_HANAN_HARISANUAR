<?php

/**
 * API Key Authentication Middleware
 *
 * Validates the X-API-Key request header
 * against the API key stored in Apache.
 */

function requireApiKey(): void
{
    $config = require __DIR__ . '/api_key.php';

    $expectedKey = $config['key'] ?? '';

    // Ensure API key is configured
    if (
        !is_string($expectedKey) ||
        strlen($expectedKey) !== 64 ||
        !ctype_xdigit($expectedKey)
    ) {
        http_response_code(500);
        header('Content-Type: application/json');

        echo json_encode([
            'success' => false,
            'message' => 'API key configuration error'
        ]);

        exit;
    }

    // Retrieve API key from request header
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

    if ($providedKey === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'X-API-Key') === 0) {
                $providedKey = $value;
                break;
            }
        }
    }

    // Validate API key
    if (
        !is_string($providedKey) ||
        !hash_equals($expectedKey, trim($providedKey))
    ) {
        http_response_code(401);
        header('Content-Type: application/json');

        echo json_encode([
            'success' => false,
            'message' => 'Missing or invalid API key'
        ]);

        exit;
    }
}