<?php

declare(strict_types=1);

use RedisSimply\Config;
use RedisSimply\Env;

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit('Redis Simply requires PHP 8.3 or newer.');
}

foreach (['redis' => 'phpredis (php-redis)', 'mbstring' => 'mbstring', 'session' => 'session'] as $extension => $name) {
    if (! extension_loaded($extension)) {
        http_response_code(500);
        exit("Redis Simply requires the {$name} extension.");
    }
}

// Installed with composer create-project (or composer install in a clone),
// vendor/autoload.php exists and is used, so anything config.php pulls in
// through Composer loads too. A release zip has no vendor directory, and
// neither does the package when another project requires it: the app has no
// dependencies, so its own classes are all there is to load.
if (is_file(__DIR__.'/vendor/autoload.php')) {
    require __DIR__.'/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'RedisSimply\\')) {
            $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen('RedisSimply\\'))).'.php';

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
function redis_simply_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
}

// .env, config.php and storage/ live here, or in REDIS_SIMPLY_HOME when the
// app is installed as a Composer dependency and must survive updates.
try {
    $home = Env::home(__DIR__);
} catch (RuntimeException $e) {
    http_response_code(500);
    exit($e->getMessage());
}

return Config::load($home);
