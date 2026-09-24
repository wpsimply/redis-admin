<?php

declare(strict_types=1);

namespace RedisSimply\Tests;

use RedisSimply\Codec;
use RedisSimply\Config;
use RedisSimply\Connection;
use RedisSimply\Env;
use RedisSimply\Formatter;
use RedisSimply\Serialized;
use RedisSimply\Session;
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

    public function testABoundTokenIsSpentOnlyByTheBrowserHoldingItsProof(): void
    {
        $dir = self::tempDir();
        $proof = bin2hex(random_bytes(32));
        $issue = static function (array $payload) use ($dir): string {
            $token = bin2hex(random_bytes(32));
            file_put_contents($dir.'/'.$token, json_encode(['target' => 'h42', ...$payload]));

            return $token;
        };

        $store = new TokenStore($dir, 60);

        self::assertSame('h42', $store->consume($issue(['binding' => TokenStore::binding($proof)]), $proof)['target']);

        // Someone else's link, opened in a browser without the proof, or with another one.
        $foreign = $issue(['binding' => TokenStore::binding($proof)]);
        self::assertThrows(UserError::class, fn () => $store->consume($foreign), 'another browser');
        self::assertThrows(UserError::class, fn () => $store->consume($foreign, $proof), 'already been used');
        self::assertThrows(UserError::class, fn () => $store->consume($issue(['binding' => TokenStore::binding($proof)]), bin2hex(random_bytes(32))), 'another browser');
        self::assertThrows(UserError::class, fn () => $store->consume($issue(['binding' => ['x']]), $proof), 'another browser');

        // Unbound tokens work until binding is required.
        self::assertSame('h42', $store->consume($issue([]))['target']);
        self::assertThrows(UserError::class, fn () => (new TokenStore($dir, 60, true))->consume($issue([]), $proof), 'not issued to a browser');
        self::assertSame([], glob($dir.'/*'));
    }

    public function testSessionKeepsThePasswordEncryptedUnderTheCookieKey(): void
    {
        $dir = self::tempDir();
        $session = new Session(Config::fromArray($dir, ['session' => ['save_path' => $dir, 'secure' => false]]));

        $session->signIn(['target' => 'h42', 'user' => 'panel', 'password' => 'hunter2', 'db' => 3, 'prefix' => null, 'label' => 'Acme']);

        self::assertSame(null, $_SESSION['grant']['password']);
        self::assertTrue(! str_contains(serialize($_SESSION), 'hunter2'), 'The password must not be stored in the session in the clear.');
        self::assertSame('hunter2', $session->grant()['password'] ?? null);
        self::assertSame(5, $session->grant('5')['db'] ?? null);
        self::assertThrows(UserError::class, fn () => $session->grant('99'), 'Unknown database');

        // Without the key cookie, the session is worthless.
        unset($_COOKIE['RedisSimplySessionKey']);
        self::assertSame(null, $session->grant());

        session_write_close();
    }

    public function testSecureSessionCookiesCannotBeSetFromASiblingSubdomain(): void
    {
        $dir = self::tempDir();
        $session = new Session(Config::fromArray($dir, ['session' => ['save_path' => $dir, 'secure' => true]]));

        $session->signIn(['target' => 'h42', 'user' => null, 'password' => 'hunter2', 'db' => 0, 'prefix' => null, 'label' => 'Acme']);

        self::assertSame('__Host-RedisSimplySession', session_name());
        self::assertTrue(isset($_COOKIE['__Host-RedisSimplySessionKey']));
        self::assertSame('', session_get_cookie_params()['domain']);

        session_write_close();
        session_name('RedisSimplySession');
    }

    public function testSignOnStartsWithAProofOnlyThisBrowserHolds(): void
    {
        $dir = self::tempDir();
        $session = new Session(Config::fromArray($dir, ['session' => ['save_path' => $dir, 'secure' => false]]));

        self::assertSame(null, $session->signOnProof());

        $binding = $session->startSignOn();
        $proof = $session->signOnProof();

        self::assertTrue($proof !== null && TokenStore::binding($proof) === $binding);

        $_COOKIE['RedisSimplySessionSignOn'] = 'not a proof';
        self::assertSame(null, $session->signOnProof());
        unset($_COOKIE['RedisSimplySessionSignOn']);
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

    public function testSerializedValuesAreReadWithoutUnserialize(): void
    {
        self::assertSame([false], Serialized::decode('b:0;'));
        self::assertSame([null], Serialized::decode('N;'));
        self::assertSame([['a', '(reference to value 2)']], Serialized::decode('a:2:{i:0;s:1:"a";i:1;R:2;}'));
        self::assertSame([['__class' => 'Legacy', '__data' => 'x:1']], Serialized::decode('C:6:"Legacy":3:{x:1}'));

        self::assertSame(null, Serialized::decode('s:5:"abc";'), 'A wrong length is not serialized data.');
        self::assertSame(null, Serialized::decode('a:1:{i:0;N;}trailing'));
        self::assertSame(null, Serialized::decode(str_repeat('a:1:{i:0;', 100).'N;'.str_repeat('}', 100)), 'Nesting is limited.');
    }

    public function testRedisCliLinesEscapeEveryByte(): void
    {
        self::assertSame('"SET" "a \\"b\\"" "x\\ny\\x00\\xff\\\\"', Transfer::line(['SET', 'a "b"', "x\ny\x00\xff\\"]));
    }
}
