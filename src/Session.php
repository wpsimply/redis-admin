<?php

declare(strict_types=1);

namespace RedisSimply;

use SodiumException;

/**
 * The signed-in session: which Redis instance it may use, and its CSRF token.
 *
 * The connection details live only in the server-side session, and nothing
 * the browser sends can change which instance a request talks to. The
 * password is kept encrypted there, under a key that lives only in a cookie
 * of its own: the session file alone, read off disk or out of a backup, does
 * not give the password away, and neither does the cookie alone.
 */
final class Session
{
    /**
     * How long a browser may take to come back from the panel with a token
     * bound to its sign-on proof, signing in to the panel included.
     */
    private const int SIGN_ON_SECONDS = 600;

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

        session_name($this->cookieName());
        session_set_cookie_params(['lifetime' => 0, ...$this->cookieOptions()]);

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

        $key = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $_SESSION['grant'] = [...$grant, 'password' => null];
        $_SESSION['secret'] = $grant['password'] === null ? null : base64_encode($nonce.sodium_crypto_secretbox($grant['password'], $nonce, $key));
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['created_at'] = time();
        $_SESSION['seen_at'] = time();

        // The proof has done its job; it may not bind a second token.
        $this->setSignOnCookie('', time() - 3600);

        $encodedKey = sodium_bin2base64($key, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $this->setKeyCookie($encodedKey, 0);
        // The rest of this request reads the grant back with the new key.
        $_COOKIE[$this->keyCookieName()] = $encodedKey;

        sodium_memzero($key);
    }

    /**
     * The current grant, with its password decrypted, or null when signed
     * out or timed out.
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

        $grant = $_SESSION['grant'];

        if ($_SESSION['secret'] !== null) {
            $password = $this->decrypt((string) $_SESSION['secret']);

            // The key cookie is gone or was changed: the session cannot be used.
            if ($password === null) {
                $this->signOut();

                return null;
            }

            $grant['password'] = $password;
        }

        $_SESSION['seen_at'] = $now;

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

    /**
     * Give this browser a fresh sign-on proof, and return the binding the
     * panel is to write into the token it issues. See {@see TokenStore}.
     */
    public function startSignOn(): string
    {
        $proof = bin2hex(random_bytes(32));
        $this->setSignOnCookie($proof, time() + self::SIGN_ON_SECONDS);
        $_COOKIE[$this->signOnCookieName()] = $proof;

        return TokenStore::binding($proof);
    }

    /**
     * The sign-on proof this browser holds, if any.
     */
    public function signOnProof(): ?string
    {
        $proof = $_COOKIE[$this->signOnCookieName()] ?? null;

        return is_string($proof) && preg_match('/^[a-f0-9]{64}$/', $proof) === 1 ? $proof : null;
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

        if (! headers_sent()) {
            setcookie(session_name(), '', [...$this->cookieOptions(), 'expires' => time() - 3600]);
        }

        $this->setKeyCookie('', time() - 3600);
    }

    private function decrypt(string $secret): ?string
    {
        $encoded = $_COOKIE[$this->keyCookieName()] ?? null;
        $box = base64_decode($secret, true);

        if (! is_string($encoded) || $box === false || strlen($box) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        try {
            $key = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException) {
            return null;
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($box, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($box, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );

        sodium_memzero($key);

        return $plain === false ? null : $plain;
    }

    private function keyCookieName(): string
    {
        return $this->cookieName().'Key';
    }

    private function signOnCookieName(): string
    {
        return $this->cookieName().'SignOn';
    }

    private function setSignOnCookie(string $value, int $expires): void
    {
        if (! headers_sent()) {
            setcookie($this->signOnCookieName(), $value, [...$this->cookieOptions(), 'expires' => $expires]);
        }
    }

    /**
     * The session cookie's name. Over HTTPS it carries the __Host- prefix:
     * the browser then only accepts the cookie from this exact host, so a
     * site on a sibling subdomain -- another account's, on a hosting server
     * -- cannot plant a session of its own in the user's browser.
     */
    private function cookieName(): string
    {
        $name = (string) $this->config->get('session.name');

        return (bool) $this->config->get('session.secure') && ! str_starts_with($name, '__Host-') ? '__Host-'.$name : $name;
    }

    private function setKeyCookie(string $value, int $expires): void
    {
        if (! headers_sent()) {
            setcookie($this->keyCookieName(), $value, [...$this->cookieOptions(), 'expires' => $expires]);
        }
    }

    /**
     * Both cookies end with the browser session, and are never shared with
     * other subdomains, whatever php.ini's session.cookie_domain says.
     *
     * @return array{path: string, domain: string, secure: bool, httponly: bool, samesite: string}
     */
    private function cookieOptions(): array
    {
        return [
            'path' => '/',
            'domain' => '',
            'secure' => (bool) $this->config->get('session.secure'),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
