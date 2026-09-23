<?php

declare(strict_types=1);

use RedisSimply\Session;
use RedisSimply\TokenStore;
use RedisSimply\UserError;

$config = require dirname(__DIR__).'/bootstrap.php';

redis_simply_headers();

$tokens = new TokenStore((string) $config->get('sso.token_dir'), $config->int('sso.token_ttl'));
$session = new Session($config);

try {
    $grant = $tokens->consume((string) ($_GET['token'] ?? ''));
} catch (UserError $e) {
    // A reload of the sign-on URL after it was spent lands the user back in
    // the session it already opened rather than on an error.
    if ($session->grant() !== null) {
        header('Location: ./');
        exit;
    }

    http_response_code($e->status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
} finally {
    $tokens->prune();
}

$session->signIn($grant);

header('Location: ./');
