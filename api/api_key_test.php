<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/request_logger.php";
require_once __DIR__ . "/../config/api_key_middleware.php";

// Start recording API requests
startRequestLogging();

// Verify API key
requireApiKey();

http_response_code(200);

echo json_encode([
    "success" => true,
    "message" => "API key authentication successful"
], JSON_PRETTY_PRINT);