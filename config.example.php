<?php

/*
 * Copy this file to config.php and adjust it. Every key is optional: anything
 * left out falls back to the defaults in src/Config.php.
 */

return [
    /*
     * How to reach the Redis instance a session was signed in to.
     *
     * The target comes from the sign-on token and is substituted for {target}.
     * It is validated against [A-Za-z0-9_-]{1,64} before it gets here, so it
     * can never walk out of the socket directory. Leave {target} out to point
     * every session at one fixed instance.
     *
     * Use either a Unix socket or a host and port.
     */
    'redis' => [
        'socket' => '/run/redis-simply/{target}.sock',
        // 'host' => '127.0.0.1',
        // 'port' => 6379,
        'timeout' => 3.0,
        'read_timeout' => 30.0,
        'databases' => 16,
    ],

    /*
     * Where sign-on tokens are dropped and how long one stays valid. The
     * directory must be writable by the PHP-FPM pool and nobody else.
     */
    'sso' => [
        'token_dir' => __DIR__.'/storage/sso-tokens',
        'token_ttl' => 60,
    ],

    'session' => [
        'save_path' => __DIR__.'/storage/sessions',
        'name' => 'RedisSimplySession',
        'secure' => true,
        'idle_timeout' => 1800,
        'lifetime' => 28800,
    ],

    /*
     * Shown on the signed-out page, so a user whose session ended knows where
     * to go to open a new one.
     */
    'panel_url' => null,

    'title' => 'Redis Simply',

    'limits' => [
        // Bytes of a string value sent to the browser before it is truncated.
        'string_preview' => 262144,
        // Bytes of one collection item before it is truncated.
        'item_preview' => 65536,
        // Keys returned per page of the key list.
        'scan_page' => 200,
        // Seconds a pattern delete may run before handing back a cursor.
        'bulk_budget' => 10,
    ],

    // Decode PHP-serialized values for display. Classes are never instantiated.
    'decode_serialized' => true,
];
