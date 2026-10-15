
<?php
// Temporary local setup script. Run through CMD only.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI access only');
}

require_once __DIR__ . '/config/database.php';

$db = new Database();
$conn = $db->connect();

$accounts = [
    1 => 'AdminDemo@2026!',
    2 => 'OrganiserDemo@2026!',
    3 => 'OrganiserDemo@2026!',
    4 => 'CustomerDemo@2026!',
    5 => 'CustomerDemo@2026!',
    6 => 'CustomerDemo@2026!'
];

$sql = "UPDATE users
        SET password_hash = ?
        WHERE user_id = ? AND password_hash = 'PENDING_HASH'";

$stmt = $conn->prepare($sql);

foreach ($accounts as $userId => $password) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt->execute([$hash, $userId]);

    echo "User ID $userId: " .
        ($stmt->rowCount() > 0 ? "Updated" : "Skipped") .
        PHP_EOL;
}

echo "Password setup completed." . PHP_EOL;
