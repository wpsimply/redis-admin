<?php

declare(strict_types=1);

use RedisSimply\Session;
use RedisSimply\TokenStore;
use RedisSimply\UserError;

$config = require dirname(__DIR__).'/bootstrap.php';

redis_simply_headers();

$session = new Session($config);

$fail = static function (string $message, int $status): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
};

// Sign-on bound to this browser starts here: a proof goes into a cookie, and
// the browser goes to the panel with its hash, to come back with a token that
// only this browser can spend. Anything else the panel's link asked for (which
// account, say) is passed along as it is.
if (isset($_GET['start'])) {
    $issueUrl = $config->get('sso.issue_url');

    if (! is_string($issueUrl) || $issueUrl === '') {
        $fail('Sign-on from Redis Simply is not set up. Open Redis Simply from your control panel.', 404);
    }

    $query = http_build_query([...array_diff_key($_GET, ['start' => true, 'binding' => true]), 'binding' => $session->startSignOn()]);

    header('Location: '.$issueUrl.(str_contains($issueUrl, '?') ? '&' : '?').$query);
    exit;
}

$tokens = new TokenStore((string) $config->get('sso.token_dir'), $config->int('sso.token_ttl'), (bool) $config->get('sso.require_binding'));

try {
    $grant = $tokens->consume((string) ($_GET['token'] ?? ''), $session->signOnProof());
} catch (UserError $e) {
    // A reload of the sign-on URL after it was spent lands the user back in
    // the session it already opened rather than on an error.
    if ($session->grant() !== null) {
        header('Location: ./');
        exit;
    }

    $fail($e->getMessage(), $e->status);
} finally {
    $tokens->prune();
}

$session->signIn($grant);

header('Location: ./');
