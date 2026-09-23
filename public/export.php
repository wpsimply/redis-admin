<?php

declare(strict_types=1);

use RedisAdmin\Client;
use RedisAdmin\Codec;
use RedisAdmin\Connection;
use RedisAdmin\Session;
use RedisAdmin\Transfer;
use RedisAdmin\UserError;

$config = require dirname(__DIR__).'/bootstrap.php';

redis_admin_headers();

$session = new Session($config);
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

try {
    $grant = $session->grant(($isPost ? $_POST : $_GET)['db'] ?? null);
} catch (UserError $e) {
    http_response_code($e->status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($e->getMessage());
}

if ($grant === null || ($isPost && ! $session->verifyCsrf($_POST['csrf'] ?? null))) {
    http_response_code($grant === null ? 401 : 419);
    header('Content-Type: text/plain; charset=utf-8');
    exit($grant === null ? 'Your session has ended. Open Redis Admin again from your control panel.' : 'Your session token is out of date. Reload the page.');
}

$session->release();

// A selection of keys is posted, since thousands of ids do not fit in a URL;
// a pattern export is a plain link.
$input = $isPost ? $_POST : $_GET;
$format = ($input['format'] ?? 'json') === 'redis' ? 'redis' : 'json';

try {
    $transfer = new Transfer(new Client((new Connection($config))->open($grant)));

    $ids = isset($input['ids']) ? array_filter(explode(',', (string) $input['ids'])) : null;

    $keys = $ids !== null
        ? array_map(Codec::fromId(...), array_values($ids))
        : $transfer->matching((string) ($input['pattern'] ?? '*'), isset($input['type']) && $input['type'] !== '' ? (string) $input['type'] : null);
} catch (UserError $e) {
    http_response_code($e->status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($e->getMessage());
}

set_time_limit(0);

$filename = sprintf('redis-%s-db%d-%s.%s', preg_replace('/[^A-Za-z0-9_-]/', '', $grant['target']), $grant['db'], gmdate('Ymd-His'), $format === 'json' ? 'json' : 'redis');

header($format === 'json' ? 'Content-Type: application/json; charset=utf-8' : 'Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('X-Accel-Buffering: no');

try {
    $transfer->export($keys, $format, $grant['db'], static function (string $chunk): void {
        echo $chunk;
        flush();
    });
} catch (UserError $e) {
    // Headers are gone by now; the best that can be done is to make the file
    // visibly incomplete rather than silently short.
    echo "\n# EXPORT FAILED: ".$e->getMessage()."\n";
}
