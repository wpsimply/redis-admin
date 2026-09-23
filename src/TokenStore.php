<?php

declare(strict_types=1);

namespace RedisSimply;

/**
 * One-time sign-on tokens.
 *
 * The control panel drops a file named after a random token into the token
 * directory, holding the connection the session is allowed to use, and sends
 * the user to sso.php?token=<token>. The token is spent on first use and
 * expires after a short window either way, so a URL that leaks through
 * history or a referrer is worthless by the time anyone else holds it.
 *
 * File contents (JSON):
 *   target    required  [A-Za-z0-9_-]{1,64}, substituted into the socket path
 *   user      optional  Redis ACL username
 *   password  optional  Redis password
 *   db        optional  database to open, default 0
 *   prefix    optional  key pattern to open the key list on
 *   label     optional  name shown in the header
 */
final class TokenStore
{
    public function __construct(private readonly string $directory, private readonly int $ttl) {}

    /**
     * Spend a token and return the session it grants.
     *
     * @return array{target: string, user: ?string, password: ?string, db: int, prefix: ?string, label: string}
     */
    public function consume(string $token): array
    {
        if (preg_match('/^[a-f0-9]{32,128}$/', $token) !== 1) {
            throw new UserError('Invalid sign-on link.', 403);
        }

        $file = $this->directory.'/'.$token;
        $claimed = $file.'.'.bin2hex(random_bytes(4)).'.claimed';

        // Renaming is atomic: of two requests racing for one token only one
        // can move the file, so a token cannot be spent twice.
        if (! @rename($file, $claimed)) {
            throw new UserError('This sign-on link is invalid or has already been used.', 403);
        }

        try {
            $age = time() - (int) filemtime($claimed);
            $payload = json_decode((string) file_get_contents($claimed), true);
        } finally {
            @unlink($claimed);
        }

        if ($age > $this->ttl) {
            throw new UserError('This sign-on link has expired.', 403);
        }

        if (! is_array($payload)) {
            throw new UserError('Invalid sign-on link.', 403);
        }

        return self::validate($payload);
    }

    /**
     * Remove tokens nobody came back for.
     */
    public function prune(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            if (is_file($file) && time() - (int) filemtime($file) > $this->ttl) {
                @unlink($file);
            }
        }
    }

    /**
     * @param  array<mixed>  $payload
     * @return array{target: string, user: ?string, password: ?string, db: int, prefix: ?string, label: string}
     */
    public static function validate(array $payload): array
    {
        $target = $payload['target'] ?? null;

        if (! is_string($target) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $target) !== 1) {
            throw new UserError('Invalid sign-on link.', 403);
        }

        $optional = static fn (string $key): ?string => isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== ''
            ? $payload[$key]
            : null;

        return [
            'target' => $target,
            'user' => $optional('user'),
            'password' => $optional('password'),
            'db' => max(0, (int) ($payload['db'] ?? 0)),
            'prefix' => $optional('prefix'),
            'label' => mb_substr($optional('label') ?? $target, 0, 120),
        ];
    }
}
