<?php

declare(strict_types=1);

namespace RedisSimply;

/**
 * Read-only facts about the instance: version, memory, hit rate, and how many
 * keys each database holds.
 *
 * INFO is in Redis's @dangerous category, so a tightly scoped ACL user may not
 * be allowed it. Everything here degrades to what DBSIZE alone can tell.
 */
final class ServerInfo
{
    private const array FIELDS = [
        'redis_version', 'redis_mode', 'os', 'uptime_in_seconds',
        'connected_clients', 'blocked_clients',
        'used_memory', 'used_memory_human', 'used_memory_peak_human', 'maxmemory', 'maxmemory_human', 'maxmemory_policy', 'mem_fragmentation_ratio',
        'total_commands_processed', 'instantaneous_ops_per_sec', 'keyspace_hits', 'keyspace_misses', 'expired_keys', 'evicted_keys',
        'rdb_last_save_time', 'aof_enabled',
    ];

    public function __construct(private readonly Client $client, private readonly int $databases) {}

    /**
     * @return array{available: bool, fields: array<string, string>, databases: list<array{db: int, keys: int, expires: int}>}
     */
    public function summary(int $currentDb): array
    {
        try {
            $info = $this->parse((string) $this->client->call('INFO'));
        } catch (UserError) {
            return [
                'available' => false,
                'fields' => [],
                'databases' => $this->databases([], $currentDb),
            ];
        }

        return [
            'available' => true,
            'fields' => array_intersect_key($info, array_flip(self::FIELDS)),
            'databases' => $this->databases($info, $currentDb),
        ];
    }

    /**
     * @param  array<string, string>  $info
     * @return list<array{db: int, keys: int, expires: int}>
     */
    private function databases(array $info, int $currentDb): array
    {
        $databases = [];

        for ($db = 0; $db < $this->databases; $db++) {
            $keys = 0;
            $expires = 0;

            if (isset($info['db'.$db]) && preg_match('/keys=(\d+),expires=(\d+)/', $info['db'.$db], $match) === 1) {
                $keys = (int) $match[1];
                $expires = (int) $match[2];
            } elseif ($db === $currentDb && $info === []) {
                $keys = (int) $this->client->call('DBSIZE');
            }

            $databases[] = ['db' => $db, 'keys' => $keys, 'expires' => $expires];
        }

        return $databases;
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $raw): array
    {
        $values = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if ($line === '' || $line[0] === '#' || ! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $values[$name] = $value;
        }

        return $values;
    }
}
