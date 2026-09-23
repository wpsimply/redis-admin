<?php

declare(strict_types=1);

namespace RedisAdmin;

/**
 * The signed-in session: which Redis instance it may use, and its CSRF token.
 *
 * The connection details live only in the server-side session. The browser
 * holds nothing but the session cookie, and nothing it sends can change which
 * instance a request talks to.
 */
final class Session
{
    public function __construct(private readonly Config $config) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $this->config->int('session.lifetime'));

        $savePath = (string) $this->config->get('session.save_path');

        if ($savePath !== '') {
            session_save_path($savePath);
        }

        session_name((string) $this->config->get('session.name'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (bool) $this->config->get('session.secure'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /**
     * Replace whatever session this browser had with one for the given grant.
     *
     * @param  array{target: string, user: ?string, password: ?string, db: int, prefix: ?string, label: string}  $grant
     */
    public function signIn(array $grant): void
    {
        $this->start();
        $_SESSION = [];
        session_regenerate_id(true);

        $_SESSION['grant'] = $grant;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['created_at'] = time();
        $_SESSION['seen_at'] = time();
    }

    /**
     * The current grant, or null when signed out or timed out.
     *
     * A request may name the database it works in. It applies to that request
     * only and is never written back: the page's URL is what remembers which
     * database a tab is on, so two tabs on two databases do not switch each
     * other's. Without one, the database the sign-on opened is used.
     *
     * @return array{target: string, user: ?string, password: ?string, db: int, prefix: ?string, label: string}|null
     */
    public function grant(mixed $db = null): ?array
    {
        $this->start();

        if (! isset($_SESSION['grant'], $_SESSION['created_at'], $_SESSION['seen_at'])) {
            return null;
        }

        $now = time();

        if ($now - $_SESSION['seen_at'] > $this->config->int('session.idle_timeout')
            || $now - $_SESSION['created_at'] > $this->config->int('session.lifetime')) {
            $this->signOut();

            return null;
        }

        $_SESSION['seen_at'] = $now;

        $grant = $_SESSION['grant'];

        if ($db !== null && $db !== '') {
            $grant['db'] = $this->database($db);
        }

        return $grant;
    }

    /**
     * Validate a database number against the configured count.
     */
    private function database(mixed $db): int
    {
        $databases = $this->config->int('redis.databases');

        if (! is_numeric($db) || (string) (int) $db !== (string) $db || (int) $db < 0 || (int) $db >= $databases) {
            throw new UserError(sprintf('Unknown database. Use 0 to %d.', $databases - 1));
        }

        return (int) $db;
    }

    public function csrf(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public function verifyCsrf(?string $token): bool
    {
        $expected = $this->csrf();

        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * Release the session lock so parallel requests from one tab do not queue
     * behind a long export or pattern delete.
     */
    public function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function signOut(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();

        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
