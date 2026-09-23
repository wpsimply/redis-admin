<?php

declare(strict_types=1);

namespace RedisSimply\Tests;

use RedisSimply\Api;
use RedisSimply\Codec;
use RedisSimply\Connection;
use RedisSimply\Session;
use RedisSimply\UserError;

final class ApiTest extends TestCase
{
    private Session $session;

    private Api $api;

    public function setUp(): void
    {
        $this->client();
        $target = $this->redisTarget();

        $config = $this->redisConfig(['session' => ['save_path' => self::tempDir()]]);
        $this->session = new Session($config);
        $this->api = new Api($config, $this->session, new Connection($config));

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->session->signIn(['target' => 'test', 'user' => null, 'password' => null, 'db' => $target['db'], 'prefix' => null, 'label' => 'Test']);
    }

    public function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        parent::tearDown();
    }

    public function testChangesRequireTheCsrfToken(): void
    {
        $body = ['key' => 'k', 'type' => 'string', 'value' => 'v'];

        self::assertThrows(UserError::class, fn () => $this->api->handle('POST', 'create', [], $body, 'wrong'), 'token');
        self::assertSame(false, $this->redis->exists('k') > 0);

        $this->session->start();
        $created = $this->api->handle('POST', 'create', [], $body, $this->session->csrf());

        self::assertSame(Codec::id('k'), $created['id']);
        self::assertSame('v', $this->redis->get('k'));
    }

    public function testReadsMustBeGetAndChangesMustBePost(): void
    {
        $this->session->start();
        $csrf = $this->session->csrf();

        self::assertThrows(UserError::class, fn () => $this->api->handle('POST', 'scan', [], [], $csrf), 'Method');
        self::assertThrows(UserError::class, fn () => $this->api->handle('GET', 'delete', ['ids' => []], [], $csrf), 'Method');
    }

    public function testSignedOutSessionsAreRejected(): void
    {
        $this->session->signOut();

        $error = self::assertThrows(UserError::class, fn () => $this->api->handle('GET', 'scan', [], []));
        self::assertSame(401, $error->status);
    }

    public function testIdleSessionsExpire(): void
    {
        $this->session->start();
        $_SESSION['seen_at'] = time() - 4000;

        self::assertSame(null, $this->session->grant());
    }

    public function testARequestWorksInTheDatabaseItNamesWithoutSwitchingTheSession(): void
    {
        $target = $this->redisTarget();
        $other = $target['db'] === 14 ? 13 : 14;

        $this->redis->select($other);
        $this->redis->flushDB();
        $this->redis->set('elsewhere', '1');
        $this->redis->select($target['db']);
        $this->redis->set('here', '1');

        try {
            $named = $this->api->handle('GET', 'scan', ['pattern' => '*', 'db' => (string) $other], []);
            $default = $this->api->handle('GET', 'scan', ['pattern' => '*'], []);

            self::assertSame(['elsewhere'], array_column($named['keys'], 'label'));
            self::assertSame(['here'], array_column($default['keys'], 'label'), 'The named database is not remembered.');
        } finally {
            $this->redis->select($other);
            $this->redis->flushDB();
            $this->redis->select($target['db']);
        }
    }

    public function testAnUnknownDatabaseIsRejected(): void
    {
        foreach (['16', '-1', '1.5', 'abc'] as $db) {
            self::assertThrows(UserError::class, fn () => $this->api->handle('GET', 'scan', ['db' => $db], []), 'Unknown database');
        }
    }

    public function testScanRunsAgainstTheGrantedDatabaseOnly(): void
    {
        $this->redis->set('mine', '1');

        $page = $this->api->handle('GET', 'scan', ['pattern' => '*'], []);

        self::assertSame(['mine'], array_column($page['keys'], 'label'));
    }
}
