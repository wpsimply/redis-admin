<?php

declare(strict_types=1);

namespace RedisSimply\Tests;

use RedisSimply\Transfer;
use RedisSimply\UserError;

final class TransferTest extends TestCase
{
    private Transfer $transfer;

    public function setUp(): void
    {
        $this->transfer = new Transfer($this->client());
    }

    public function testJsonExportImportsBackToTheSameData(): void
    {
        $snapshot = $this->seed();

        $json = $this->export('json');
        $this->redis->flushDB();

        $result = $this->transfer->import($json, replace: false);

        self::assertSame(['imported' => 7, 'skipped' => 0, 'failed' => []], $result);
        self::assertSame($snapshot, $this->snapshot());
        self::assertTrue($this->redis->pttl('str') > 0, 'The TTL should be restored.');
    }

    public function testImportSkipsOrReplacesExistingKeys(): void
    {
        $this->redis->set('k', 'exported');
        $json = $this->export('json');
        $this->redis->set('k', 'changed');

        self::assertSame(1, $this->transfer->import($json, replace: false)['skipped']);
        self::assertSame('changed', $this->redis->get('k'));

        self::assertSame(1, $this->transfer->import($json, replace: true)['imported']);
        self::assertSame('exported', $this->redis->get('k'));
    }

    public function testImportReportsBadEntriesAndKeepsGoing(): void
    {
        $json = json_encode(['format' => 'redis-simply', 'version' => 1, 'keys' => [
            ['key' => 'good', 'type' => 'string', 'ttl' => -1, 'value' => 'v'],
            ['key' => 'bad', 'type' => 'hash', 'ttl' => -1, 'value' => [['only-one']]],
            ['key' => 'weird', 'type' => 'nope', 'ttl' => -1, 'value' => 'v'],
        ]]);

        $result = $this->transfer->import((string) $json, replace: false);

        self::assertSame(1, $result['imported']);
        self::assertSame(['bad', 'weird'], array_column($result['failed'], 'key'));
        self::assertThrows(UserError::class, fn () => $this->transfer->import('{"hello": 1}', false), 'not a Redis Simply');
    }

    public function testRedisCliExportReplaysToTheSameData(): void
    {
        $cli = trim((string) shell_exec('command -v redis-cli'));

        if ($cli === '') {
            throw new Skipped('redis-cli is not installed.');
        }

        $snapshot = $this->seed();
        $script = $this->export('redis');
        $this->redis->flushDB();

        $target = $this->redisTarget();
        $connection = $target['socket'] !== null
            ? '-s '.escapeshellarg($target['socket'])
            : '-h '.escapeshellarg($target['host']).' -p '.$target['port'];

        $process = proc_open(sprintf('%s %s -n %d', $cli, $connection, $target['db']), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        proc_close($process);

        self::assertSame($snapshot, $this->snapshot());
    }

    /**
     * Seed one key of every type, including binary data, and return a snapshot.
     *
     * @return array<string, mixed>
     */
    private function seed(): array
    {
        $this->redis->setex('str', 3600, "line\nbreak \"quoted\"");
        $this->redis->set("bin\xff", "\x00\x01\xfe");
        $this->redis->hMSet('hash', ['a' => '1', "b\xff" => "\x00"]);
        $this->redis->rPush('list', 'x', 'y', 'x');
        $this->redis->sAdd('set', 'm1', 'm2');
        $this->redis->zAdd('zset', 1.5, 'a', -2, 'b');
        $this->redis->xAdd('stream', '1-1', ['f' => 'v']);

        return $this->snapshot();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $keys = $this->redis->keys('*');
        sort($keys);
        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[bin2hex($key)] = $this->redis->dump($key);
        }

        return $snapshot;
    }

    private function export(string $format): string
    {
        $buffer = '';
        $this->transfer->export($this->transfer->matching('*', null), $format, 0, function (string $chunk) use (&$buffer): void {
            $buffer .= $chunk;
        });

        return $buffer;
    }
}
