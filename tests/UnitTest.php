<?php

declare(strict_types=1);

namespace RedisSimply\Tests;

use RedisSimply\Codec;
use RedisSimply\Config;
use RedisSimply\Connection;
use RedisSimply\Env;
use RedisSimply\Formatter;
use RedisSimply\TokenStore;
use RedisSimply\Transfer;
use RedisSimply\UserError;
use RuntimeException;

/**
 * Everything that needs no Redis.
 */
final class UnitTest extends TestCase
{
    public function testCodecRoundTripsBinaryAndText(): void
    {
        $binary = "\x00\xff\xfe key";

        self::assertSame('plain', Codec::encode('plain'));
        self::assertSame(['$b64' => base64_encode($binary)], Codec::encode($binary));
        self::assertSame($binary, Codec::decode(Codec::encode($binary)));
        self::assertSame($binary, Codec::fromId(Codec::id($binary)));
        self::assertSame('a\xffb', Codec::display("a\xffb"));
        self::assertSame('ünï', Codec::display('ünï'));
    }

    public function testCodecRejectsMalformedReferences(): void
    {
        self::assertThrows(UserError::class, fn () => Codec::fromId('not/valid'));
        self::assertThrows(UserError::class, fn () => Codec::fromId(''));
        self::assertThrows(UserError::class, fn () => Codec::decode(['$b64' => '***']));
        self::assertThrows(UserError::class, fn () => Codec::decode(['nope']));
    }

    public function testEnvParsesTheCommonDotenvSubset(): void
    {
        $parsed = Env::parse(<<<'ENV'
            # comment
            export REDIS_SIMPLY_TITLE="My \"Redis\""
            REDIS_SIMPLY_HOST=10.0.0.1 # inline comment
            REDIS_SIMPLY_PANEL_URL='https://panel.test/#hash'
            REDIS_SIMPLY_SOCKET=
            not a line
            ENV);

        self::assertSame('My "Redis"', $parsed['REDIS_SIMPLY_TITLE']);
        self::assertSame('10.0.0.1', $parsed['REDIS_SIMPLY_HOST']);
        self::assertSame('https://panel.test/#hash', $parsed['REDIS_SIMPLY_PANEL_URL']);
        self::assertSame('', $parsed['REDIS_SIMPLY_SOCKET']);
    }

    public function testRealEnvironmentWinsOverDotenvAndEmptyMeansDefault(): void
    {
        $dir = self::tempDir();
        file_put_contents($dir.'/.env', "REDIS_SIMPLY_PORT=7000\nREDIS_SIMPLY_HOST=file-host\nREDIS_SIMPLY_TOKEN_DIR=\nREDIS_SIMPLY_SESSION_SECURE=false\n");

        $overrides = Env::overrides($dir.'/.env', ['REDIS_SIMPLY_HOST' => 'env-host']);
        $config = Config::fromArray($dir, $overrides);

        self::assertSame('env-host', $config->get('redis.host'));
        self::assertSame(7000, $config->get('redis.port'));
        self::assertSame(false, $config->get('session.secure'));
        self::assertSame($dir.'/storage/sso-tokens', $config->get('sso.token_dir'));
    }

    public function testHomeComesFromTheRealEnvironmentOnly(): void
    {
        $dir = self::tempDir();

        self::assertSame('/app', Env::home('/app', []));
        self::assertSame('/app', Env::home('/app', ['REDIS_SIMPLY_HOME' => ' ']));
        self::assertSame(realpath($dir), Env::home('/app', ['REDIS_SIMPLY_HOME' => $dir.'/']));
        self::assertThrows(RuntimeException::class, fn () => Env::home('/app', ['REDIS_SIMPLY_HOME' => $dir.'/missing']), 'not a directory');

        file_put_contents($dir.'/.env', "REDIS_SIMPLY_TITLE=From home\n");
        $config = Config::fromArray($dir, Env::overrides($dir.'/.env', []));

        self::assertSame('From home', $config->get('title'));
        self::assertSame($dir.'/storage/sessions', $config->get('session.save_path'));
    }

    public function testTokenIsSpentOnFirstUse(): void
    {
        $dir = self::tempDir();
        $token = bin2hex(random_bytes(32));
        file_put_contents($dir.'/'.$token, json_encode(['target' => 'h42', 'user' => 'panel', 'password' => 'secret', 'db' => 2, 'label' => 'Acme']));

        $store = new TokenStore($dir, 60);
        $grant = $store->consume($token);

        self::assertSame(['target' => 'h42', 'user' => 'panel', 'password' => 'secret', 'db' => 2, 'prefix' => null, 'label' => 'Acme'], $grant);
        self::assertThrows(UserError::class, fn () => $store->consume($token), 'already been used');
        self::assertSame([], glob($dir.'/*'));
    }

    public function testExpiredTokenIsRejectedAndRemoved(): void
    {
        $dir = self::tempDir();
        $token = bin2hex(random_bytes(32));
        file_put_contents($dir.'/'.$token, json_encode(['target' => 'h1']));
        touch($dir.'/'.$token, time() - 120);

        self::assertThrows(UserError::class, fn () => (new TokenStore($dir, 60))->consume($token), 'expired');
        self::assertSame([], glob($dir.'/*'));
    }

    public function testTokenCannotNameATargetOutsideTheSocketDirectory(): void
    {
        $dir = self::tempDir();

        foreach (['../../etc/passwd', 'a/b', '', 'x y'] as $target) {
            $token = bin2hex(random_bytes(32));
            file_put_contents($dir.'/'.$token, json_encode(['target' => $target]));

            self::assertThrows(UserError::class, fn () => (new TokenStore($dir, 60))->consume($token));
        }

        self::assertThrows(UserError::class, fn () => (new TokenStore($dir, 60))->consume('../'.str_repeat('a', 40)));
    }

    public function testSocketPathIsBuiltFromTheTemplate(): void
    {
        $connection = new Connection(Config::fromArray(__DIR__, ['redis' => ['socket' => '/run/redis-simply/{target}.sock']]));

        self::assertSame('/run/redis-simply/h7.sock', $connection->socketFor('h7'));
        self::assertThrows(UserError::class, fn () => $connection->socketFor('../h7'));
        self::assertSame(null, (new Connection(Config::fromArray(__DIR__, [])))->socketFor('h7'));
    }

    public function testFormatterRecognisesJsonAndSerializedPhpWithoutInstantiatingClasses(): void
    {
        $formatter = new Formatter;

        self::assertSame('json', $formatter->describe('{"a":1}')['format']);
        self::assertSame('text', $formatter->describe('{not json')['format']);
        self::assertSame('binary', $formatter->describe("\xff\x00")['format']);

        $serialized = serialize(['user' => new \ArrayObject(['x' => 1]), 'n' => 5]);
        $described = $formatter->describe($serialized);

        self::assertSame('serialized', $described['format']);
        self::assertTrue(str_contains((string) $described['pretty'], '"__class": "ArrayObject"'));
        self::assertSame('text', (new Formatter(false))->describe($serialized)['format']);

        // Private and protected property names carry NUL bytes.
        $withPrivate = 'O:8:"WP_Thing":2:{s:12:"'."\0".'WP_Thing'."\0".'id";i:7;s:7:"'."\0".'*'."\0".'name";s:1:"x";}';
        $described = $formatter->describe($withPrivate);

        self::assertSame('serialized', $described['format']);
        self::assertTrue(str_contains((string) $described['pretty'], '"id": 7') && str_contains((string) $described['pretty'], '"name": "x"'));
    }

    public function testRedisCliLinesEscapeEveryByte(): void
    {
        self::assertSame('"SET" "a \\"b\\"" "x\\ny\\x00\\xff\\\\"', Transfer::line(['SET', 'a "b"', "x\ny\x00\xff\\"]));
    }
}
