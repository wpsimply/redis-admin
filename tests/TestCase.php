<?php

declare(strict_types=1);

namespace RedisAdmin\Tests;

use Closure;
use Redis;
use RedisAdmin\Client;
use RedisAdmin\Config;
use RuntimeException;
use Throwable;

final class Skipped extends RuntimeException {}

final class AssertionFailed extends RuntimeException {}

abstract class TestCase
{
    protected ?Redis $redis = null;

    public function setUp(): void {}

    public function tearDown(): void
    {
        $this->redis?->flushDB();
        $this->redis?->close();
    }

    protected static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(trim($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true).'.'));
        }
    }

    protected static function assertTrue(bool $condition, string $message = 'Expected true.'): void
    {
        if (! $condition) {
            throw new AssertionFailed($message);
        }
    }

    /**
     * @param  class-string<Throwable>  $class
     */
    protected static function assertThrows(string $class, Closure $callback, ?string $messageContains = null): Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if (! $e instanceof $class) {
                throw new AssertionFailed(sprintf('Expected %s, got %s: %s', $class, $e::class, $e->getMessage()));
            }

            if ($messageContains !== null && ! str_contains($e->getMessage(), $messageContains)) {
                throw new AssertionFailed(sprintf('Expected the message to contain "%s", got "%s".', $messageContains, $e->getMessage()));
            }

            return $e;
        }

        throw new AssertionFailed(sprintf('Expected %s to be thrown.', $class));
    }

    /**
     * The test Redis connection details, or a skip when none are configured.
     *
     * @return array{socket: ?string, host: string, port: int, db: int}
     */
    protected function redisTarget(): array
    {
        $socket = getenv('REDIS_ADMIN_TEST_SOCKET') ?: null;
        $host = getenv('REDIS_ADMIN_TEST_HOST') ?: null;

        if ($socket === null && $host === null) {
            throw new Skipped('No test Redis configured.');
        }

        return [
            'socket' => $socket,
            'host' => $host ?? '127.0.0.1',
            'port' => (int) (getenv('REDIS_ADMIN_TEST_PORT') ?: 6379),
            'db' => (int) (getenv('REDIS_ADMIN_TEST_DB') ?: 15),
        ];
    }

    /**
     * A config pointing at the test Redis.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function redisConfig(array $overrides = []): Config
    {
        $target = $this->redisTarget();

        return Config::fromArray(dirname(__DIR__), array_replace_recursive([
            'redis' => ['socket' => $target['socket'], 'host' => $target['host'], 'port' => $target['port']],
        ], $overrides));
    }

    /**
     * A client on a freshly flushed test database.
     */
    protected function client(): Client
    {
        $target = $this->redisTarget();
        $this->redis = new Redis;
        $target['socket'] !== null ? $this->redis->connect($target['socket']) : $this->redis->connect($target['host'], $target['port']);
        $this->redis->select($target['db']);
        $this->redis->flushDB();

        return new Client($this->redis);
    }

    protected static function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/redis-admin-test-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);

        return $dir;
    }
}
