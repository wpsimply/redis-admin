<?php

declare(strict_types=1);

namespace RedisAdmin;

/**
 * Reads configuration from the environment and an optional .env file.
 *
 * Only REDIS_ADMIN_* variables are considered. A variable set in the real
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
        'REDIS_ADMIN_TITLE' => ['title', 'string'],
        'REDIS_ADMIN_PANEL_URL' => ['panel_url', 'string'],
        'REDIS_ADMIN_SOCKET' => ['redis.socket', 'string'],
        'REDIS_ADMIN_HOST' => ['redis.host', 'string'],
        'REDIS_ADMIN_PORT' => ['redis.port', 'int'],
        'REDIS_ADMIN_TIMEOUT' => ['redis.timeout', 'float'],
        'REDIS_ADMIN_READ_TIMEOUT' => ['redis.read_timeout', 'float'],
        'REDIS_ADMIN_DATABASES' => ['redis.databases', 'int'],
        'REDIS_ADMIN_TOKEN_DIR' => ['sso.token_dir', 'string'],
        'REDIS_ADMIN_TOKEN_TTL' => ['sso.token_ttl', 'int'],
        'REDIS_ADMIN_SESSION_PATH' => ['session.save_path', 'string'],
        'REDIS_ADMIN_SESSION_NAME' => ['session.name', 'string'],
        'REDIS_ADMIN_SESSION_SECURE' => ['session.secure', 'bool'],
        'REDIS_ADMIN_SESSION_IDLE_TIMEOUT' => ['session.idle_timeout', 'int'],
        'REDIS_ADMIN_SESSION_LIFETIME' => ['session.lifetime', 'int'],
        'REDIS_ADMIN_STRING_PREVIEW' => ['limits.string_preview', 'int'],
        'REDIS_ADMIN_ITEM_PREVIEW' => ['limits.item_preview', 'int'],
        'REDIS_ADMIN_SCAN_PAGE' => ['limits.scan_page', 'int'],
        'REDIS_ADMIN_BULK_BUDGET' => ['limits.bulk_budget', 'int'],
        'REDIS_ADMIN_DECODE_SERIALIZED' => ['decode_serialized', 'bool'],
    ];

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
            if (is_string($name) && str_starts_with($name, 'REDIS_ADMIN_') && is_string($value)) {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
