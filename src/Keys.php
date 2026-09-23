<?php

declare(strict_types=1);

namespace RedisAdmin;

/**
 * Everything the UI does to keys: listing, reading, creating, editing and
 * deleting them.
 *
 * Keys and values are handled as raw bytes throughout; {@see Codec} is what
 * turns them into JSON on the way out and back into bytes on the way in.
 */
final class Keys
{
    public const array TYPES = ['string', 'hash', 'list', 'set', 'zset', 'stream'];

    /**
     * Type, TTL, length and memory for a batch of keys in one round trip.
     */
    private const string META_SCRIPT = <<<'LUA'
        local out = {}
        for i, key in ipairs(KEYS) do
            local kind = redis.call('TYPE', key).ok
            local length = 0
            if kind == 'string' then length = redis.call('STRLEN', key)
            elseif kind == 'hash' then length = redis.call('HLEN', key)
            elseif kind == 'list' then length = redis.call('LLEN', key)
            elseif kind == 'set' then length = redis.call('SCARD', key)
            elseif kind == 'zset' then length = redis.call('ZCARD', key)
            elseif kind == 'stream' then length = redis.call('XLEN', key)
            end
            local memory = -1
            if ARGV[1] == '1' and kind ~= 'none' then
                local usage = redis.pcall('MEMORY', 'USAGE', key)
                if type(usage) == 'number' then memory = usage end
            end
            out[i] = {kind, redis.call('PTTL', key), length, memory}
        end
        return out
        LUA;

    /**
     * Rewrite or remove one list element, but only if it is still the element
     * the user was looking at: indexes shift under concurrent pushes and pops.
     */
    private const string LIST_SCRIPT = <<<'LUA'
        local current = redis.call('LINDEX', KEYS[1], ARGV[1])
        if not current or redis.sha1hex(current) ~= ARGV[2] then
            return redis.error_reply('STALE')
        end
        redis.call('LSET', KEYS[1], ARGV[1], ARGV[4])
        if ARGV[3] == 'delete' then
            redis.call('LREM', KEYS[1], 1, ARGV[4])
        end
        return 1
        LUA;

    private bool $memoryAllowed = true;

    public function __construct(
        private readonly Client $client,
        private readonly Formatter $formatter,
        private readonly int $stringPreview = 262144,
        private readonly int $itemPreview = 65536,
    ) {}

    /**
     * List a page of keys matching a pattern.
     *
     * SCAN may return few or no keys per call on a sparse match, so this keeps
     * scanning until it has a page, the keyspace is exhausted, or it has spent
     * its time budget, and hands back the cursor to continue from.
     *
     * @return array{cursor: string, keys: list<array<string, mixed>>}
     */
    public function scan(string $pattern, ?string $type, string $cursor, int $count): array
    {
        $cursor = preg_match('/^\d{1,20}$/', $cursor) === 1 ? $cursor : '0';
        $pattern = $pattern === '' ? '*' : $pattern;
        $deadline = microtime(true) + 2.0;
        $found = [];

        do {
            $command = ['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', max($count, 500)];

            if ($type !== null) {
                $command[] = 'TYPE';
                $command[] = $this->assertType($type);
            }

            [$cursor, $batch] = $this->client->call(...$command);

            foreach ($batch as $key) {
                $found[$key] = true;
            }
        } while ($cursor !== '0' && count($found) < $count && microtime(true) < $deadline);

        $keys = array_map('strval', array_keys($found));
        sort($keys, SORT_STRING);

        return ['cursor' => $cursor, 'keys' => $this->meta($keys)];
    }

    /**
     * Describe a batch of keys. Keys deleted since they were listed are dropped.
     *
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    public function meta(array $keys): array
    {
        $described = [];

        foreach (array_chunk($keys, 200) as $chunk) {
            $rows = $this->metaRows($chunk);

            foreach ($chunk as $i => $key) {
                [$kind, $ttl, $length, $memory] = $rows[$i];

                if ($kind === 'none') {
                    continue;
                }

                $described[] = [
                    'id' => Codec::id($key),
                    'key' => Codec::encode($key),
                    'label' => Codec::display($key),
                    'type' => $kind,
                    'ttl' => (int) $ttl,
                    'length' => (int) $length,
                    'memory' => (int) $memory >= 0 ? (int) $memory : null,
                ];
            }
        }

        return $described;
    }

    /**
     * Read a key: its metadata plus one page of its value.
     *
     * @param  array{cursor?: string, offset?: int, count?: int}  $page
     * @return array<string, mixed>
     */
    public function inspect(string $key, array $page = []): array
    {
        $meta = $this->meta([$key])[0] ?? null;

        if ($meta === null) {
            throw new UserError('The key no longer exists.', 404);
        }

        $count = max(1, min(500, (int) ($page['count'] ?? 100)));
        $offset = max(0, (int) ($page['offset'] ?? 0));
        $cursor = (string) ($page['cursor'] ?? '0');
        $cursor = preg_match('/^\d{1,20}$/', $cursor) === 1 ? $cursor : '0';

        $meta['encoding'] = $this->encoding($key);

        return $meta + match ($meta['type']) {
            'string' => $this->readString($key, $meta['length']),
            'hash' => $this->readHash($key, $cursor, $count),
            'list' => $this->readList($key, $offset, $count),
            'set' => $this->readSet($key, $cursor, $count),
            'zset' => $this->readZset($key, $offset, $count),
            'stream' => $this->readStream($key, $page['cursor'] ?? null, $count),
            default => ['supported' => false],
        };
    }

    /**
     * Create a key with a first value.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(string $key, string $type, array $input, ?int $ttl): void
    {
        if ($key === '') {
            throw new UserError('The key name cannot be empty.');
        }

        $type = $this->assertType($type);

        if ((int) $this->client->call('EXISTS', $key) === 1) {
            throw new UserError('A key with this name already exists.', 409);
        }

        $write = match ($type) {
            'string' => ['SET', $key, Codec::decode($input['value'] ?? '')],
            'hash' => ['HSET', $key, $this->required($input, 'field'), Codec::decode($input['value'] ?? '')],
            'list' => ['RPUSH', $key, Codec::decode($input['value'] ?? '')],
            'set' => ['SADD', $key, $this->required($input, 'member')],
            'zset' => ['ZADD', $key, $this->score($input['score'] ?? 0), $this->required($input, 'member')],
            'stream' => ['XADD', $key, '*', $this->required($input, 'field'), Codec::decode($input['value'] ?? '')],
        };

        $commands = [$write];

        if ($ttl !== null) {
            $commands[] = ['EXPIRE', $key, $this->ttl($ttl)];
        }

        $this->client->transaction($commands);
    }

    /**
     * Replace a string value, keeping its TTL.
     */
    public function setString(string $key, string $value): void
    {
        $this->expectType($key, 'string');
        $this->client->call('SET', $key, $value, 'KEEPTTL');
    }

    /**
     * Add or overwrite a hash field; renames it when the original differs.
     */
    public function setHashField(string $key, ?string $original, string $field, string $value): void
    {
        $this->expectType($key, 'hash', allowMissing: $original === null);

        $commands = [['HSET', $key, $field, $value]];

        if ($original !== null && $original !== $field) {
            $commands[] = ['HDEL', $key, $original];
        }

        $this->client->transaction($commands);
    }

    public function deleteHashField(string $key, string $field): void
    {
        $this->client->call('HDEL', $key, $field);
    }

    public function pushList(string $key, string $value, bool $head): void
    {
        $this->expectType($key, 'list');
        $this->client->call($head ? 'LPUSH' : 'RPUSH', $key, $value);
    }

    public function setListItem(string $key, int $index, string $expectedHash, string $value): void
    {
        $this->listScript($key, $index, $expectedHash, 'set', $value);
    }

    public function deleteListItem(string $key, int $index, string $expectedHash): void
    {
        $this->listScript($key, $index, $expectedHash, 'delete', 'redis-admin:tombstone:'.bin2hex(random_bytes(16)));
    }

    /**
     * Add a set member, or replace one when the original differs.
     */
    public function setSetMember(string $key, ?string $original, string $member): void
    {
        $this->expectType($key, 'set', allowMissing: $original === null);

        $commands = [];

        if ($original !== null && $original !== $member) {
            $commands[] = ['SREM', $key, $original];
        }

        $commands[] = ['SADD', $key, $member];

        $this->client->transaction($commands);
    }

    public function deleteSetMember(string $key, string $member): void
    {
        $this->client->call('SREM', $key, $member);
    }

    /**
     * Add or re-score a sorted set member, or replace one when the original differs.
     */
    public function setZsetMember(string $key, ?string $original, string $member, mixed $score): void
    {
        $this->expectType($key, 'zset', allowMissing: $original === null);

        $commands = [];

        if ($original !== null && $original !== $member) {
            $commands[] = ['ZREM', $key, $original];
        }

        $commands[] = ['ZADD', $key, $this->score($score), $member];

        $this->client->transaction($commands);
    }

    public function deleteZsetMember(string $key, string $member): void
    {
        $this->client->call('ZREM', $key, $member);
    }

    /**
     * Append a stream entry.
     *
     * @param  list<array{0: string, 1: string}>  $fields
     */
    public function addStreamEntry(string $key, array $fields): string
    {
        $this->expectType($key, 'stream');

        if ($fields === []) {
            throw new UserError('A stream entry needs at least one field.');
        }

        return (string) $this->client->call('XADD', $key, '*', ...array_merge(...$fields));
    }

    public function deleteStreamEntry(string $key, string $id): void
    {
        if (preg_match('/^\d+-\d+$/', $id) !== 1) {
            throw new UserError('Invalid stream entry id.');
        }

        $this->client->call('XDEL', $key, $id);
    }

    public function rename(string $key, string $newKey, bool $overwrite): void
    {
        if ($newKey === '') {
            throw new UserError('The key name cannot be empty.');
        }

        if ($newKey === $key) {
            return;
        }

        if ($overwrite) {
            $this->client->call('RENAME', $key, $newKey);

            return;
        }

        if ((int) $this->client->call('RENAMENX', $key, $newKey) === 0) {
            throw new UserError('A key with the new name already exists.', 409);
        }
    }

    /**
     * Set a TTL in seconds, or remove it with null.
     */
    public function expire(string $key, ?int $ttl): void
    {
        $result = $ttl === null
            ? $this->client->call('PERSIST', $key)
            : $this->client->call('EXPIRE', $key, $this->ttl($ttl));

        if ($ttl !== null && (int) $result === 0) {
            throw new UserError('The key no longer exists.', 404);
        }
    }

    /**
     * Delete keys, returning how many existed.
     *
     * @param  list<string>  $keys
     */
    public function delete(array $keys): int
    {
        $deleted = 0;

        foreach (array_chunk($keys, 500) as $chunk) {
            $deleted += (int) $this->client->call('UNLINK', ...$chunk);
        }

        return $deleted;
    }

    /**
     * Delete keys matching a pattern for up to the given number of seconds.
     *
     * @return array{cursor: string, deleted: int, done: bool}
     */
    public function deleteMatching(string $pattern, ?string $type, string $cursor, float $budget): array
    {
        if ($pattern === '') {
            throw new UserError('A pattern is required.');
        }

        $cursor = preg_match('/^\d{1,20}$/', $cursor) === 1 ? $cursor : '0';
        $deadline = microtime(true) + $budget;
        $deleted = 0;

        do {
            $command = ['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', 1000];

            if ($type !== null) {
                $command[] = 'TYPE';
                $command[] = $this->assertType($type);
            }

            [$cursor, $batch] = $this->client->call(...$command);

            if ($batch !== []) {
                $deleted += $this->delete(array_map('strval', $batch));
            }
        } while ($cursor !== '0' && microtime(true) < $deadline);

        return ['cursor' => $cursor, 'deleted' => $deleted, 'done' => $cursor === '0'];
    }

    /**
     * @return list<array{0: string, 1: int|string, 2: int|string, 3: int|string}>
     */
    private function metaRows(array $keys): array
    {
        $script = ['EVAL', self::META_SCRIPT, count($keys), ...$keys, $this->memoryAllowed ? '1' : '0'];

        try {
            return $this->client->call(...$script);
        } catch (UserError $e) {
            if (! str_starts_with($e->getMessage(), 'Not permitted') || ! $this->memoryAllowed) {
                throw $e;
            }

            $this->memoryAllowed = false;
            $script[array_key_last($script)] = '0';

            return $this->client->call(...$script);
        }
    }

    private function encoding(string $key): ?string
    {
        try {
            $encoding = $this->client->call('OBJECT', 'ENCODING', $key);
        } catch (UserError) {
            return null;
        }

        return is_string($encoding) ? $encoding : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readString(string $key, int $length): array
    {
        $complete = $length <= $this->stringPreview;
        $bytes = (string) ($complete
            ? $this->client->call('GET', $key)
            : $this->client->call('GETRANGE', $key, 0, $this->stringPreview - 1));

        return [
            'value' => Codec::encode($bytes),
            'truncated' => ! $complete,
            ...$this->formatter->describe($bytes, $complete),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readHash(string $key, string $cursor, int $count): array
    {
        [$next, $flat] = $this->client->call('HSCAN', $key, $cursor, 'COUNT', $count);
        $items = [];

        for ($i = 0, $n = count($flat); $i < $n; $i += 2) {
            $items[] = ['field' => Codec::encode((string) $flat[$i]), ...$this->preview((string) $flat[$i + 1])];
        }

        return ['items' => $items, 'cursor' => $next];
    }

    /**
     * @return array<string, mixed>
     */
    private function readList(string $key, int $offset, int $count): array
    {
        $values = $this->client->call('LRANGE', $key, $offset, $offset + $count - 1) ?? [];
        $items = [];

        foreach ($values as $i => $value) {
            $items[] = ['index' => $offset + $i, 'hash' => sha1((string) $value), ...$this->preview((string) $value)];
        }

        return ['items' => $items, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    private function readSet(string $key, string $cursor, int $count): array
    {
        [$next, $members] = $this->client->call('SSCAN', $key, $cursor, 'COUNT', $count);

        return [
            'items' => array_map(static fn ($member): array => ['member' => Codec::encode((string) $member)], $members),
            'cursor' => $next,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readZset(string $key, int $offset, int $count): array
    {
        $flat = $this->client->call('ZRANGE', $key, $offset, $offset + $count - 1, 'WITHSCORES') ?? [];
        $items = [];

        for ($i = 0, $n = count($flat); $i < $n; $i += 2) {
            $items[] = ['member' => Codec::encode((string) $flat[$i]), 'score' => (string) $flat[$i + 1]];
        }

        return ['items' => $items, 'offset' => $offset];
    }

    /**
     * Newest entries first; the cursor is the id to continue below.
     *
     * @return array<string, mixed>
     */
    private function readStream(string $key, ?string $before, int $count): array
    {
        $end = is_string($before) && preg_match('/^\d+-\d+$/', $before) === 1 ? '('.$before : '+';
        $entries = $this->client->call('XREVRANGE', $key, $end, '-', 'COUNT', $count) ?? [];
        $items = [];

        foreach ($entries as [$id, $flat]) {
            $fields = [];

            for ($i = 0, $n = count($flat); $i < $n; $i += 2) {
                $fields[] = ['field' => Codec::encode((string) $flat[$i]), ...$this->preview((string) $flat[$i + 1])];
            }

            $items[] = ['id' => $id, 'fields' => $fields];
        }

        return [
            'items' => $items,
            'cursor' => count($entries) === $count ? (string) end($entries)[0] : '0',
        ];
    }

    /**
     * An item value cut down to the preview size.
     *
     * @return array{value: string|array{'$b64': string}, truncated: bool, format: string}
     */
    private function preview(string $value): array
    {
        $truncated = strlen($value) > $this->itemPreview;
        $shown = $truncated ? substr($value, 0, $this->itemPreview) : $value;

        return [
            'value' => Codec::encode($shown),
            'truncated' => $truncated,
            'format' => $this->formatter->describe($shown, ! $truncated)['format'],
        ];
    }

    private function listScript(string $key, int $index, string $expectedHash, string $mode, string $value): void
    {
        try {
            $this->client->call('EVAL', self::LIST_SCRIPT, 1, $key, $index, $expectedHash, $mode, $value);
        } catch (UserError $e) {
            if (str_contains($e->getMessage(), 'STALE')) {
                throw new UserError('The list changed since it was loaded. Refresh and try again.', 409);
            }

            throw $e;
        }
    }

    /**
     * Make sure a key holds the type an edit expects, so an edit made against a
     * stale view cannot turn a key into something else.
     */
    private function expectType(string $key, string $type, bool $allowMissing = false): void
    {
        $actual = (string) $this->client->call('TYPE', $key);

        if ($actual === 'none' && ! $allowMissing) {
            throw new UserError('The key no longer exists.', 404);
        }

        if ($actual !== 'none' && $actual !== $type) {
            throw new UserError(sprintf('The key now holds a %s, not a %s.', $actual, $type), 409);
        }
    }

    private function assertType(string $type): string
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new UserError('Unknown key type.');
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function required(array $input, string $name): string
    {
        $value = Codec::decode($input[$name] ?? '');

        if ($value === '') {
            throw new UserError(sprintf('The %s cannot be empty.', $name));
        }

        return $value;
    }

    private function score(mixed $score): string
    {
        $score = is_string($score) ? trim($score) : $score;

        if (in_array($score, ['inf', '+inf', '-inf'], true)) {
            return (string) $score;
        }

        if (! is_numeric($score)) {
            throw new UserError('The score must be a number.');
        }

        return (string) $score;
    }

    private function ttl(int $ttl): int
    {
        if ($ttl < 1) {
            throw new UserError('The TTL must be at least one second.');
        }

        return $ttl;
    }
}
