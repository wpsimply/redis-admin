<?php

declare(strict_types=1);

namespace RedisAdmin;

/**
 * The application's configuration: the defaults below, overlaid with whatever
 * config.php returns.
 */
final class Config
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private readonly array $values) {}

    /**
     * Load the configuration for the application in the given directory.
     *
     * Three layers, each overriding the one before: the defaults below, the
     * REDIS_ADMIN_* environment (.env, with the real environment winning), and
     * config.php. Use whichever suits the deployment; most need only one.
     */
    public static function load(string $root): self
    {
        $values = self::merge(self::defaults($root), Env::overrides($root.'/.env'));

        $file = $root.'/config.php';
        $overrides = is_file($file) ? require $file : [];

        return new self(self::merge($values, is_array($overrides) ? $overrides : []));
    }

    /**
     * Build a config from an array, over the defaults. Used by the tests.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function fromArray(string $root, array $overrides): self
    {
        return new self(self::merge(self::defaults($root), $overrides));
    }

    /**
     * Read a value by dot-separated path.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->values;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function int(string $path): int
    {
        return (int) $this->get($path);
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(string $root): array
    {
        return [
            'redis' => [
                'socket' => null,
                'host' => '127.0.0.1',
                'port' => 6379,
                'timeout' => 3.0,
                'read_timeout' => 30.0,
                'databases' => 16,
            ],
            'sso' => [
                'token_dir' => $root.'/storage/sso-tokens',
                'token_ttl' => 60,
            ],
            'session' => [
                'save_path' => $root.'/storage/sessions',
                'name' => 'RedisAdminSession',
                'secure' => true,
                'idle_timeout' => 1800,
                'lifetime' => 28800,
            ],
            'panel_url' => null,
            'title' => 'Redis Admin',
            'limits' => [
                'string_preview' => 262144,
                'item_preview' => 65536,
                'scan_page' => 200,
                'bulk_budget' => 10,
            ],
            'decode_serialized' => true,
        ];
    }

    /**
     * Recursively overlay associative arrays; lists and scalars are replaced.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
