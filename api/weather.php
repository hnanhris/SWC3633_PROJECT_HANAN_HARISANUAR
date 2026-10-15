<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/auth.php";

// Only authenticated users can access weather information
requireAuth();

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Method not allowed"
    ]);
    exit;
}

// Kuala Lumpur coordinates
$latitude = 3.1390;
$longitude = 101.6869;

$url = "https://api.open-meteo.com/v1/forecast?" .
    http_build_query([
        "latitude" => $latitude,
        "longitude" => $longitude,
        "current" => "temperature_2m,relative_humidity_2m,weather_code",
        "timezone" => "Asia/Kuala_Lumpur"
    ]);

// Check whether cURL is available
if (!function_exists("curl_init")) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "PHP cURL extension is not enabled"
    ]);
    exit;
}

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

if ($response === false || $statusCode !== 200) {
    http_response_code(502);

    echo json_encode([
        "success" => false,
        "message" => "Unable to retrieve external weather data"
    ]);
    exit;
}

$data = json_decode($response, true);

if (
    !is_array($data) ||
    !isset(
        $data["current"]["temperature_2m"],
        $data["current"]["relative_humidity_2m"],
        $data["current"]["weather_code"]
    )
) {
    http_response_code(502);

    echo json_encode([
        "success" => false,
        "message" => "Invalid weather data received"
    ]);
    exit;
}

http_response_code(200);

echo json_encode([
    "success" => true,
    "message" => "Weather data retrieved successfully",
    "source" => "Open-Meteo",
    "location" => "Kuala Lumpur",
    "weather" => [
        "temperature_celsius" =>
            $data["current"]["temperature_2m"],
        "humidity_percent" =>
            $data["current"]["relative_humidity_2m"],
        "weather_code" =>
            $data["current"]["weather_code"],
        "observation_time" =>
            $data["current"]["time"] ?? null
    ]
], JSON_PRETTY_PRINT);