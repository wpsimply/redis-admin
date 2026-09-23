<?php

declare(strict_types=1);

use RedisAdmin\Client;
use RedisAdmin\Connection;
use RedisAdmin\Session;
use RedisAdmin\Transfer;
use RedisAdmin\UserError;

$config = require dirname(__DIR__).'/bootstrap.php';

redis_admin_headers();
header('Content-Type: application/json; charset=utf-8');

$session = new Session($config);

try {
    $grant = $session->grant($_POST['db'] ?? null);

    if ($grant === null) {
        throw new UserError('Your session has ended. Open Redis Admin again from your control panel.', 401);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new UserError('Method not allowed.', 405);
    }

    if (! $session->verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        throw new UserError('Your session token is out of date. Reload the page.', 419);
    }

    $session->release();

    $upload = $_FILES['file'] ?? null;

    if (! is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ! is_uploaded_file($upload['tmp_name'])) {
        throw new UserError(match ($upload['error'] ?? UPLOAD_ERR_NO_FILE) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server accepts.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to import.',
            default => 'The upload failed.',
        });
    }

    set_time_limit(0);

    $transfer = new Transfer(new Client((new Connection($config))->open($grant)));
    $result = $transfer->import((string) file_get_contents($upload['tmp_name']), ($_POST['mode'] ?? 'skip') === 'replace');

    echo json_encode(['data' => $result], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (UserError $e) {
    http_response_code($e->status);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
