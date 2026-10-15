
<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/config/database.php";

try {
    $database = new Database();
    $conn = $database->connect();

    echo json_encode([
        "success" => true,
        "message" => "Event Ticketing API is running!",
        "database" => "Connected successfully"
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Server connection error"
    ]);
}
