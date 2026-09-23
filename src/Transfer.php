<?php

declare(strict_types=1);

namespace RedisAdmin;

use Generator;

/**
 * Export keys to a file and import them back.
 *
 * Two export formats:
 * - **json**: this application's own format, which the import reads. Every key
 *   and value goes through {@see Codec}, so binary data survives, and hashes are
 *   written as [field, value] pairs rather than objects for the same reason.
 * - **redis**: a script of commands that `redis-cli` can replay
 *   (`redis-cli < export.redis`). Each key is deleted first, so replaying it
 *   restores the exported state instead of appending to what is there.
 *
 * Collections are read in chunks, and the file is written as it is read, so an
 * export never holds more than one key in memory.
 */
final class Transfer
{
    public const int FORMAT_VERSION = 1;

    private const int CHUNK = 500;

    public function __construct(private readonly Client $client) {}

    /**
     * Keys matching a pattern, a batch at a time.
     *
     * @return Generator<int, string>
     */
    public function matching(string $pattern, ?string $type): Generator
    {
        $cursor = '0';

        do {
            $command = ['SCAN', $cursor, 'MATCH', $pattern === '' ? '*' : $pattern, 'COUNT', 1000];

            if ($type !== null && in_array($type, Keys::TYPES, true)) {
                $command[] = 'TYPE';
                $command[] = $type;
            }

            [$cursor, $batch] = $this->client->call(...$command);

            foreach ($batch as $key) {
                yield (string) $key;
            }
        } while ($cursor !== '0');
    }

    /**
     * Write an export of the given keys.
     *
     * @param  iterable<string>  $keys
     * @param  callable(string): void  $write
     */
    public function export(iterable $keys, string $format, int $db, callable $write): int
    {
        $count = 0;

        if ($format === 'json') {
            $write(sprintf(
                "{\n\"format\": \"redis-admin\",\n\"version\": %d,\n\"db\": %d,\n\"exported_at\": %s,\n\"keys\": [",
                self::FORMAT_VERSION,
                $db,
                json_encode(gmdate('c')),
            ));
        } else {
            $write(sprintf("# Redis Admin export, db %d, %s\n# Replay with: redis-cli -n %d < this-file\n", $db, gmdate('c'), $db));
        }

        foreach ($keys as $key) {
            $entry = $this->read($key);

            if ($entry === null) {
                continue;
            }

            if ($format === 'json') {
                $write(($count > 0 ? ",\n" : "\n").$this->jsonEntry($entry));
            } else {
                $write($this->commands($entry));
            }

            $count++;
        }

        if ($format === 'json') {
            $write("\n]\n}\n");
        }

        return $count;
    }

    /**
     * Read one key in full, or null if it vanished or is of an unsupported type.
     *
     * @return array{key: string, type: string, ttl: int, value: mixed}|null
     */
    public function read(string $key): ?array
    {
        $type = (string) $this->client->call('TYPE', $key);

        $value = match ($type) {
            'string' => $this->client->call('GET', $key),
            'hash' => $this->pairs($this->scanAll('HSCAN', $key)),
            'list' => $this->rangeAll('LRANGE', $key),
            'set' => $this->scanAll('SSCAN', $key),
            'zset' => $this->pairs($this->rangeAll('ZRANGE', $key, 'WITHSCORES')),
            'stream' => $this->streamAll($key),
            default => null,
        };

        if ($value === null) {
            return null;
        }

        return ['key' => $key, 'type' => $type, 'ttl' => (int) $this->client->call('PTTL', $key), 'value' => $value];
    }

    /**
     * Import a JSON export.
     *
     * @param  bool  $replace  overwrite keys that exist; otherwise they are skipped
     * @return array{imported: int, skipped: int, failed: list<array{key: string, error: string}>}
     */
    public function import(string $json, bool $replace): array
    {
        $document = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);

        if (! is_array($document) || ($document['format'] ?? null) !== 'redis-admin' || ! isset($document['keys']) || ! is_array($document['keys'])) {
            throw new UserError('This is not a Redis Admin JSON export.');
        }

        if ((int) ($document['version'] ?? 0) > self::FORMAT_VERSION) {
            throw new UserError('This export was made by a newer version of Redis Admin.');
        }

        $result = ['imported' => 0, 'skipped' => 0, 'failed' => []];

        foreach ($document['keys'] as $entry) {
            $label = is_array($entry) && isset($entry['key']) ? Codec::display($this->safeDecode($entry['key'])) : '?';

            try {
                $key = Codec::decode($entry['key'] ?? null);

                if (! $replace && (int) $this->client->call('EXISTS', $key) === 1) {
                    $result['skipped']++;

                    continue;
                }

                $this->client->transaction($this->restoreCommands($key, $entry));
                $result['imported']++;
            } catch (UserError $e) {
                $result['failed'][] = ['key' => $label, 'error' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * The commands that recreate one exported key from scratch.
     *
     * @param  array<string, mixed>  $entry
     * @return list<list<string|int>>
     */
    private function restoreCommands(string $key, array $entry): array
    {
        $type = $entry['type'] ?? null;
        $value = $entry['value'] ?? null;

        if (! in_array($type, Keys::TYPES, true)) {
            throw new UserError('Unsupported key type.');
        }

        if ($type !== 'string' && (! is_array($value) || ! array_is_list($value))) {
            throw new UserError('Malformed value.');
        }

        $commands = [['DEL', $key]];

        if ($type === 'string') {
            $commands[] = ['SET', $key, Codec::decode($value)];
        } elseif ($type === 'stream') {
            foreach ($value as $item) {
                $id = $item['id'] ?? null;

                if (! is_string($id) || preg_match('/^\d+-\d+$/', $id) !== 1 || ! is_array($item['fields'] ?? null) || $item['fields'] === []) {
                    throw new UserError('Malformed stream entry.');
                }

                $commands[] = ['XADD', $key, $id, ...$this->flattenPairs($item['fields'])];
            }
        } else {
            $command = ['hash' => 'HSET', 'list' => 'RPUSH', 'set' => 'SADD', 'zset' => 'ZADD'][$type];

            foreach (array_chunk($value, self::CHUNK) as $chunk) {
                $arguments = match ($type) {
                    'hash' => $this->flattenPairs($chunk),
                    'zset' => $this->flattenScores($chunk),
                    default => array_map(Codec::decode(...), $chunk),
                };

                $commands[] = [$command, $key, ...$arguments];
            }
        }

        $ttl = (int) ($entry['ttl'] ?? -1);

        if ($ttl > 0) {
            $commands[] = ['PEXPIRE', $key, $ttl];
        }

        return $commands;
    }

    /**
     * @param  array{key: string, type: string, ttl: int, value: mixed}  $entry
     */
    private function jsonEntry(array $entry): string
    {
        $value = match ($entry['type']) {
            'string' => Codec::encode($entry['value']),
            'hash' => array_map(static fn (array $pair): array => [Codec::encode($pair[0]), Codec::encode($pair[1])], $entry['value']),
            'zset' => array_map(static fn (array $pair): array => [Codec::encode($pair[0]), $pair[1]], $entry['value']),
            'stream' => array_map(static fn (array $item): array => [
                'id' => $item['id'],
                'fields' => array_map(static fn (array $pair): array => [Codec::encode($pair[0]), Codec::encode($pair[1])], $item['fields']),
            ], $entry['value']),
            default => array_map(Codec::encode(...), $entry['value']),
        };

        return (string) json_encode([
            'key' => Codec::encode($entry['key']),
            'type' => $entry['type'],
            'ttl' => $entry['ttl'],
            'value' => $value,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array{key: string, type: string, ttl: int, value: mixed}  $entry
     */
    private function commands(array $entry): string
    {
        $key = $entry['key'];
        $lines = [self::line(['DEL', $key])];

        if ($entry['type'] === 'string') {
            $lines[] = self::line(['SET', $key, $entry['value']]);
        } elseif ($entry['type'] === 'stream') {
            foreach ($entry['value'] as $item) {
                $lines[] = self::line(['XADD', $key, $item['id'], ...array_merge(...$item['fields'])]);
            }
        } else {
            $command = ['hash' => 'HSET', 'list' => 'RPUSH', 'set' => 'SADD', 'zset' => 'ZADD'][$entry['type']];

            foreach (array_chunk($entry['value'], 100) as $chunk) {
                $arguments = match ($entry['type']) {
                    'hash' => array_merge(...$chunk),
                    'zset' => array_merge(...array_map(static fn (array $pair): array => [$pair[1], $pair[0]], $chunk)),
                    default => $chunk,
                };

                $lines[] = self::line([$command, $key, ...$arguments]);
            }
        }

        if ($entry['ttl'] > 0) {
            $lines[] = self::line(['PEXPIRE', $key, (string) $entry['ttl']]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * One redis-cli command line, every argument double-quoted and escaped the
     * way redis-cli's own parser reads it back.
     *
     * @param  list<string>  $arguments
     */
    public static function line(array $arguments): string
    {
        return implode(' ', array_map(static function (string $argument): string {
            $quoted = '';

            for ($i = 0, $n = strlen($argument); $i < $n; $i++) {
                $byte = $argument[$i];
                $code = ord($byte);

                $quoted .= match (true) {
                    $byte === '"' => '\\"',
                    $byte === '\\' => '\\\\',
                    $byte === "\n" => '\\n',
                    $byte === "\r" => '\\r',
                    $byte === "\t" => '\\t',
                    $code < 0x20 || $code >= 0x7f => sprintf('\\x%02x', $code),
                    default => $byte,
                };
            }

            return '"'.$quoted.'"';
        }, $arguments));
    }

    /**
     * Every element of a collection read with a *SCAN command.
     *
     * @return list<string>
     */
    private function scanAll(string $command, string $key): array
    {
        $cursor = '0';
        $items = [];

        do {
            [$cursor, $batch] = $this->client->call($command, $key, $cursor, 'COUNT', self::CHUNK);
            array_push($items, ...array_map('strval', $batch));
        } while ($cursor !== '0');

        if ($command === 'SSCAN') {
            // SSCAN may return an element more than once across calls.
            return array_values(array_unique($items));
        }

        return $items;
    }

    /**
     * Every element of a list or sorted set, read in index ranges.
     *
     * @return list<string>
     */
    private function rangeAll(string $command, string $key, string ...$flags): array
    {
        $items = [];
        $step = $flags === [] ? self::CHUNK : self::CHUNK * 2;

        for ($start = 0; ; $start += self::CHUNK) {
            $batch = $this->client->call($command, $key, $start, $start + self::CHUNK - 1, ...$flags) ?? [];
            array_push($items, ...array_map('strval', $batch));

            if (count($batch) < $step) {
                return $items;
            }
        }
    }

    /**
     * @return list<array{id: string, fields: list<array{0: string, 1: string}>}>
     */
    private function streamAll(string $key): array
    {
        $items = [];
        $start = '-';

        while (true) {
            $batch = $this->client->call('XRANGE', $key, $start, '+', 'COUNT', self::CHUNK) ?? [];

            foreach ($batch as [$id, $flat]) {
                $items[] = ['id' => (string) $id, 'fields' => $this->pairs(array_map('strval', $flat))];
            }

            if (count($batch) < self::CHUNK) {
                return $items;
            }

            $start = '('.end($batch)[0];
        }
    }

    /**
     * Pair up a flat [a, b, c, d] reply, de-duplicating by first element
     * (HSCAN may repeat a field across calls).
     *
     * @param  list<string>  $flat
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(array $flat): array
    {
        $pairs = [];

        for ($i = 0, $n = count($flat); $i + 1 < $n; $i += 2) {
            $pairs[$flat[$i]] = [$flat[$i], $flat[$i + 1]];
        }

        return array_values($pairs);
    }

    /**
     * @param  array<mixed>  $pairs
     * @return list<string>
     */
    private function flattenPairs(array $pairs): array
    {
        $flat = [];

        foreach ($pairs as $pair) {
            if (! is_array($pair) || count($pair) !== 2) {
                throw new UserError('Malformed field/value pair.');
            }

            $flat[] = Codec::decode($pair[0]);
            $flat[] = Codec::decode($pair[1]);
        }

        return $flat;
    }

    /**
     * @param  array<mixed>  $pairs
     * @return list<string>
     */
    private function flattenScores(array $pairs): array
    {
        $flat = [];

        foreach ($pairs as $pair) {
            if (! is_array($pair) || count($pair) !== 2 || ! is_numeric($pair[1]) && ! in_array($pair[1], ['inf', '+inf', '-inf'], true)) {
                throw new UserError('Malformed sorted set member.');
            }

            $flat[] = (string) $pair[1];
            $flat[] = Codec::decode($pair[0]);
        }

        return $flat;
    }

    private function safeDecode(mixed $value): string
    {
        try {
            return Codec::decode($value);
        } catch (UserError) {
            return '?';
        }
    }
}
