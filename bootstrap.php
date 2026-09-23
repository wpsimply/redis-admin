<?php

declare(strict_types=1);

use RedisAdmin\Config;

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit('Redis Admin requires PHP 8.3 or newer.');
}

if (! extension_loaded('redis')) {
    http_response_code(500);
    exit('Redis Admin requires the phpredis extension (php-redis).');
}

// Composer's autoloader when the app was installed with Composer; a release
// unpacked from a tarball has no vendor directory and needs none.
if (is_file(__DIR__.'/vendor/autoload.php')) {
    require __DIR__.'/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'RedisAdmin\\')) {
            $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen('RedisAdmin\\'))).'.php';

            if (is_file($file)) {
                require $file;
            }
        }
    });
}

/**
 * Headers every response carries: nothing may frame the app, the sign-on
 * token in the URL must never leak through a referrer, and scripts only load
 * from this origin. Alpine evaluates its directives with Function(), which is
 * what 'unsafe-eval' is for; no directive is ever built from Redis data.
 */
function redis_admin_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
}

return Config::load(__DIR__);
