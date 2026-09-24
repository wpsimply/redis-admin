<?php

declare(strict_types=1);

namespace RedisSimply;

use RuntimeException;

/**
 * Reads configuration from the environment and an optional .env file.
 *
 * Only REDIS_SIMPLY_* variables are considered. A variable set in the real
 * environment (PHP-FPM's `env[...]`, a container, the shell) wins over the
 * same variable in .env, the way dotenv loaders usually behave. Nothing is
 * written back into the process environment.
 *
 * The .env syntax is the common subset: `KEY=value`, optional `export `,
 * `#` comments, and single or double quotes. Double-quoted values understand
 * \n, \t, \" and \\. There is no variable interpolation.
 */
final class Env
{
    /**
     * Environment variable => config path, with the type it is read as.
     */
    public const array MAP = [
        'REDIS_SIMPLY_TITLE' => ['title', 'string'],
        'REDIS_SIMPLY_PANEL_URL' => ['panel_url', 'string'],
        'REDIS_SIMPLY_SOCKET' => ['redis.socket', 'string'],
        'REDIS_SIMPLY_HOST' => ['redis.host', 'string'],
        'REDIS_SIMPLY_PORT' => ['redis.port', 'int'],
        'REDIS_SIMPLY_TIMEOUT' => ['redis.timeout', 'float'],
        'REDIS_SIMPLY_READ_TIMEOUT' => ['redis.read_timeout', 'float'],
        'REDIS_SIMPLY_DATABASES' => ['redis.databases', 'int'],
        'REDIS_SIMPLY_TOKEN_DIR' => ['sso.token_dir', 'string'],
        'REDIS_SIMPLY_TOKEN_TTL' => ['sso.token_ttl', 'int'],
        'REDIS_SIMPLY_SSO_ISSUE_URL' => ['sso.issue_url', 'string'],
        'REDIS_SIMPLY_SSO_REQUIRE_BINDING' => ['sso.require_binding', 'bool'],
        'REDIS_SIMPLY_SESSION_PATH' => ['session.save_path', 'string'],
        'REDIS_SIMPLY_SESSION_NAME' => ['session.name', 'string'],
        'REDIS_SIMPLY_SESSION_SECURE' => ['session.secure', 'bool'],
        'REDIS_SIMPLY_SESSION_IDLE_TIMEOUT' => ['session.idle_timeout', 'int'],
        'REDIS_SIMPLY_SESSION_LIFETIME' => ['session.lifetime', 'int'],
        'REDIS_SIMPLY_STRING_PREVIEW' => ['limits.string_preview', 'int'],
        'REDIS_SIMPLY_ITEM_PREVIEW' => ['limits.item_preview', 'int'],
        'REDIS_SIMPLY_SCAN_PAGE' => ['limits.scan_page', 'int'],
        'REDIS_SIMPLY_BULK_BUDGET' => ['limits.bulk_budget', 'int'],
        'REDIS_SIMPLY_DECODE_SERIALIZED' => ['decode_serialized', 'bool'],
    ];

    /**
     * The directory holding .env, config.php and storage/: REDIS_SIMPLY_HOME
     * when the real environment sets it, the application directory otherwise.
     * It locates .env, so .env itself cannot set it.
     *
     * An install under vendor/ is replaced wholesale on every Composer update;
     * pointing this outside it keeps the configuration and sessions.
     *
     * @param  array<string, string>|null  $environment  defaults to the real environment
     */
    public static function home(string $default, ?array $environment = null): string
    {
        $dir = trim(($environment ?? self::environment())['REDIS_SIMPLY_HOME'] ?? '');

        if ($dir === '') {
            return $default;
        }

        $real = realpath($dir);

        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("REDIS_SIMPLY_HOME is not a directory: {$dir}");
        }

        return $real;
    }

    /**
     * The config overrides the environment describes, as a nested array.
     *
     * @param  array<string, string>|null  $environment  defaults to the real environment
     * @return array<string, mixed>
     */
    public static function overrides(?string $file, ?array $environment = null): array
    {
        $values = [
            ...($file !== null && is_file($file) ? self::parse((string) file_get_contents($file)) : []),
            ...($environment ?? self::environment()),
        ];

        $overrides = [];

        foreach (self::MAP as $name => [$path, $type]) {
            if (! array_key_exists($name, $values)) {
                continue;
            }

            $value = self::cast($values[$name], $type);

            // An empty variable, as .env.example ships most of them, means
            // "use the default" rather than "set this to nothing".
            if ($value === null) {
                continue;
            }

            $cursor = &$overrides;

            foreach (explode('.', $path) as $segment) {
                $cursor[$segment] ??= [];
                $cursor = &$cursor[$segment];
            }

            $cursor = $value;
            unset($cursor);
        }

        return $overrides;
    }

    /**
     * Parse .env contents into name => value.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $values[$match[1]] = self::value($match[2]);
        }

        return $values;
    }

    private static function value(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        if ($raw[0] === '"' && preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $raw, $match) === 1) {
            return strtr($match[1], ['\\n' => "\n", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\']);
        }

        if ($raw[0] === "'" && preg_match("/^'([^']*)'/", $raw, $match) === 1) {
            return $match[1];
        }

        // Unquoted: an inline comment starts at " #".
        return trim((string) preg_replace('/\s+#.*$/', '', $raw));
    }

    private static function cast(string $value, string $type): mixed
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return match ($type) {
            'int' => (int) $trimmed,
            'float' => (float) $trimmed,
            'bool' => in_array(strtolower($trimmed), ['1', 'true', 'yes', 'on'], true),
            default => in_array(strtolower($trimmed), ['', 'null'], true) ? null : $value,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $values = [];

        foreach ([...$_SERVER, ...$_ENV, ...getenv()] as $name => $value) {
            if (is_string($name) && str_starts_with($name, 'REDIS_SIMPLY_') && is_string($value)) {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
