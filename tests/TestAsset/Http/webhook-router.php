<?php

declare(strict_types=1);

/**
 * Router for the PHP built-in server used by the webhook integration tests.
 * Records each request as JSON in the directory named by WEBHOOK_LOG_DIR and
 * answers with the status code taken from the first path segment.
 */

$dir    = (string) getenv('WEBHOOK_LOG_DIR');
$status = (int) trim((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH), characters: '/');

file_put_contents(
    $dir . '/' . uniqid('request-', more_entropy: true) . '.json',
    json_encode([
        'method'  => $_SERVER['REQUEST_METHOD'],
        'uri'     => $_SERVER['REQUEST_URI'],
        'headers' => getallheaders(),
        'body'    => file_get_contents('php://input'),
    ]),
);

http_response_code($status >= 100 ? $status : 200);
echo 'ok';
