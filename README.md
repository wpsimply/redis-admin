# Redis Admin

A small, self-hosted web UI for Redis and Valkey, built to sit next to a hosting control panel the way phpMyAdmin does: the panel signs the user in with a one-time link, and the session can only ever reach the one instance that link was issued for.

- Browse and search keys with `SCAN` (text or glob patterns, type filter)
- View and edit strings, hashes, lists, sets, sorted sets and streams
- JSON and PHP-serialized values are decoded for reading (classes are never instantiated); binary data is shown and edited as base64
- Rename, set or remove TTLs, delete one key, a selection, or everything matching a pattern
- Export to JSON (re-importable) or a `redis-cli` script; import JSON, skipping or replacing existing keys
- Server overview: memory, hit rate, evictions, keys per database
- No build step, no runtime dependencies: plain PHP 8.3+, phpredis, and a vendored copy of Alpine.js

## Requirements

- PHP 8.3 or newer with the `redis` (phpredis) and `mbstring` extensions
- Redis 6.2+ or Valkey 7+
- A web server that serves only the `public/` directory

## Install

Download a release and unpack it, or clone the repository:

```sh
git clone https://github.com/wpsimply/redis-admin.git /var/www/redis-admin
cd /var/www/redis-admin
cp .env.example .env
```

Composer is optional. If you prefer it:

```sh
composer create-project wpsimply/redis-admin /var/www/redis-admin
```

Then make the storage directories writable by the PHP-FPM pool user and nobody else:

```sh
chown -R www-data:www-data storage
chmod 700 storage/sessions storage/sso-tokens
```

Point the web server at `public/`. There are examples for nginx and PHP-FPM in [`examples/`](examples).

## Configure

Configuration comes from three layers, each overriding the one before:

1. the defaults in `src/Config.php`
2. `REDIS_ADMIN_*` environment variables, read from `.env` and the real environment (the real environment wins)
3. `config.php`, if present (copy `config.example.php`)

Most installs only need `.env`. The settings that matter:

| Variable | Purpose |
| --- | --- |
| `REDIS_ADMIN_SOCKET` | Unix socket to connect to. `{target}` is replaced by the target named in the sign-on token, e.g. `/run/redis-admin/{target}.sock`. |
| `REDIS_ADMIN_HOST`, `REDIS_ADMIN_PORT` | Used when no socket is set. |
| `REDIS_ADMIN_TOKEN_DIR` | Where the control panel drops sign-on tokens. Defaults to `storage/sso-tokens`. |
| `REDIS_ADMIN_TOKEN_TTL` | Seconds a token stays valid. Default 60. |
| `REDIS_ADMIN_PANEL_URL` | Linked from the signed-out page. |
| `REDIS_ADMIN_SESSION_SECURE` | Keep `true` in production; `false` only for local HTTP. |

See [`.env.example`](.env.example) for all of them.

## Signing users in

There is no login form. Your control panel authorises the user, writes a token file and redirects them:

1. Generate a random token: 32–64 bytes, hex-encoded.
2. Write `<token-dir>/<token>` containing JSON, readable by the PHP-FPM pool:

   ```json
   {
     "target": "h42",
     "user": "panel",
     "password": "…",
     "db": 0,
     "prefix": "wp_abc:",
     "label": "example.com"
   }
   ```

   | Field | |
   | --- | --- |
   | `target` | Required. `[A-Za-z0-9_-]{1,64}`. Substituted into `REDIS_ADMIN_SOCKET`. |
   | `user`, `password` | Redis ACL credentials. Omit `user` to authenticate with a password only. |
   | `db` | Database to open. |
   | `prefix` | Optional: open the key list filtered to keys starting with this. |
   | `label` | Shown in the header. |

3. Redirect the user to `https://redis.example.com/sso.php?token=<token>`.

The token is spent on first use and expires after `REDIS_ADMIN_TOKEN_TTL` seconds either way. The connection details stay in the server-side session. Nothing the browser sends can change which instance or socket a request uses.

[`examples/issue-token.php`](examples/issue-token.php) shows the panel side.

## Security notes

- **Scope the Redis user.** The app never exposes a raw command console. Even so, give it an ACL user that cannot reconfigure the server, for example:
  `ACL SETUSER panel on >secret ~* &* +@all -@admin -@dangerous +info`
  That user can do everything this app does, but can't run `CONFIG`, `ACL`, `FLUSHALL`, `KEYS`, `DEBUG` or `SHUTDOWN`. Leave out `+info` and the server overview is hidden; the key browser works either way.
- Tokens are single-use, short-lived and never logged by the app. Pages are sent with `Referrer-Policy: no-referrer`, so the token URL doesn't leak to other sites.
- Every change needs the session's CSRF token. Sessions end after `REDIS_ADMIN_SESSION_IDLE_TIMEOUT` seconds of inactivity, or after `REDIS_ADMIN_SESSION_LIFETIME` seconds regardless of activity.
- The Content-Security-Policy allows scripts only from this origin. Alpine.js needs `'unsafe-eval'` to evaluate its directives. No directive is ever built from Redis data, and values are only ever rendered as text.

## Development

```sh
php -S 127.0.0.1:8080 -t public     # with REDIS_ADMIN_SESSION_SECURE=false in .env
php tests/run.php                   # unit tests only
REDIS_ADMIN_TEST_SOCKET=/tmp/redis.sock php tests/run.php   # plus Redis integration tests
```

The Redis tests flush the database they use (`REDIS_ADMIN_TEST_DB`, default 15). Never point them at an instance that holds data you care about.

To get a session locally, drop a token into `storage/sso-tokens/` and open `/sso.php?token=…`:

```sh
t=$(php -r 'echo bin2hex(random_bytes(32));'); echo '{"target":"local"}' > storage/sso-tokens/$t; echo "http://127.0.0.1:8080/sso.php?token=$t"
```

## License

MIT. Alpine.js is bundled under its own MIT license, see `public/assets/vendor/alpine.LICENSE.md`.
