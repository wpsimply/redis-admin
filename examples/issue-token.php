<?php

/*
 * The control panel's side of sign-on: authorise the user yourself, then
 * write a token and redirect. Run this on (or over SSH to) the host where
 * Redis Admin is installed; the token file must be readable by its PHP-FPM
 * pool and by nothing else.
 */

$tokenDir = '/var/www/redis-admin/storage/sso-tokens';

$token = bin2hex(random_bytes(32));

file_put_contents($tokenDir.'/'.$token, json_encode([
    'target' => 'h42',            // -> /run/redis-admin/h42.sock
    'user' => 'panel',
    'password' => 'the-acl-password',
    'db' => 0,
    'prefix' => 'wp_abc:',        // optional: open filtered to one site
    'label' => 'example.com',
]));

chmod($tokenDir.'/'.$token, 0600);

header('Location: https://redis.example.com/sso.php?token='.$token);
