
<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;

function respond($status, $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

$config = require __DIR__ . '/../config/jwt_secret.php';

if (
    empty($config['secret']) ||
    strlen($config['secret']) < 32
) {
    respond(500, [
        'success' => false,
        'message' => 'JWT configuration error'
    ]);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON body'
    ]);
}

$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if (
    !is_string($email) ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !is_string($password) ||
    $password === ''
) {
    respond(400, [
        'success' => false,
        'message' => 'Valid email and password are required'
    ]);
}

try {
    $db = new Database();
    $conn = $db->connect();

    $stmt = $conn->prepare(
        "SELECT user_id, full_name, email, password_hash, role
         FROM users
         WHERE email = ?
         LIMIT 1"
    );

    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond(401, [
            'success' => false,
            'message' => 'Invalid email or password'
        ]);
    }

    $now = time();

    $payload = [
        'iss' => $config['issuer'],
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + $config['expiry_seconds'],
        'sub' => (string) $user['user_id'],
        'role' => $user['role']
    ];

    $token = JWT::encode($payload, $config['secret'], 'HS256');

    respond(200, [
        'success' => true,
        'message' => 'Login successful',
        'token_type' => 'Bearer',
        'access_token' => $token,
        'expires_in' => $config['expiry_seconds'],
        'user' => [
            'user_id' => (int) $user['user_id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ]);

} catch (Throwable $e) {
    error_log('Login API error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
