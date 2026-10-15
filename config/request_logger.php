<?php

/**
 * Request Logging Middleware
 * Records API request activity without storing passwords,
 * JWT tokens, API keys or request bodies.
 */

function startRequestLogging(): void
{
    $startTime = microtime(true);

    register_shutdown_function(function () use ($startTime) {
        $logDirectory = __DIR__ . '/../logs';

        if (!is_dir($logDirectory)) {
            if (!mkdir($logDirectory, 0750, true) && !is_dir($logDirectory)) {
                error_log('Unable to create API log directory');
                return;
            }
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';

        // Exclude query parameters to avoid logging sensitive data
        $path = parse_url(
            $_SERVER['REQUEST_URI'] ?? '/',
            PHP_URL_PATH
        );

        $status = http_response_code();
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $entry = json_encode([
            'timestamp' => date('c'),
            'method' => $method,
            'endpoint' => $path,
            'status_code' => $status ?: 200,
            'response_time_ms' => $duration
        ], JSON_UNESCAPED_SLASHES);

        if ($entry !== false) {
            file_put_contents(
                $logDirectory . '/api_requests.log',
                $entry . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
    });
}