<?php

declare(strict_types=1);

namespace RedisAdmin\Tests;

use RedisAdmin\Api;
use RedisAdmin\Codec;
use RedisAdmin\Connection;
use RedisAdmin\Session;
use RedisAdmin\UserError;

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

    public function testScanRunsAgainstTheGrantedDatabaseOnly(): void
    {
        $this->redis->set('mine', '1');

        $page = $this->api->handle('GET', 'scan', ['pattern' => '*'], []);

        self::assertSame(['mine'], array_column($page['keys'], 'label'));
    }
}
