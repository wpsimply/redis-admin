<?php

declare(strict_types=1);

use RedisSimply\Api;
use RedisSimply\Connection;
use RedisSimply\Session;
use RedisSimply\UserError;

$config = require dirname(__DIR__).'/bootstrap.php';

redis_simply_headers();
header('Content-Type: application/json; charset=utf-8');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = [];

if ($method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);

    if (! is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Expected a JSON body.']);
        exit;
    }
}

$api = new Api($config, new Session($config), new Connection($config));

try {
    $data = $api->handle($method, (string) ($_GET['action'] ?? ''), $_GET, $body, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    echo json_encode(['data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (UserError $e) {
    http_response_code($e->status);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('redis-simply: '.$e);
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong. The error has been logged.']);
}
