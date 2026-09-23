<?php

declare(strict_types=1);

namespace RedisAdmin\Tests;

use RedisAdmin\Codec;
use RedisAdmin\Formatter;
use RedisAdmin\Keys;
use RedisAdmin\UserError;

final class KeysTest extends TestCase
{
    private Keys $keys;

    public function setUp(): void
    {
        $this->keys = new Keys($this->client(), new Formatter, stringPreview: 16, itemPreview: 8);
    }

    public function testScanPagesThroughMatchingKeysOfAType(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->redis->set("user:$i", 'x');
        }

        $this->redis->hSet('user:hash', 'f', 'v');
        $this->redis->set('other', 'x');

        $seen = [];
        $cursor = '0';

        do {
            $page = $this->keys->scan('user:*', 'string', $cursor, 10);
            array_push($seen, ...array_column($page['keys'], 'label'));
            $cursor = $page['cursor'];
        } while ($cursor !== '0');

        self::assertSame(30, count(array_unique($seen)));
        self::assertTrue(! in_array('user:hash', $seen, true) && ! in_array('other', $seen, true));
    }

    public function testCreatesEveryTypeAndRefusesToOverwrite(): void
    {
        $this->keys->create('s', 'string', ['value' => 'hello'], 100);
        $this->keys->create('h', 'hash', ['field' => 'f', 'value' => 'v'], null);
        $this->keys->create('l', 'list', ['value' => 'a'], null);
        $this->keys->create('st', 'set', ['member' => 'm'], null);
        $this->keys->create('z', 'zset', ['member' => 'm', 'score' => '1.5'], null);
        $this->keys->create('x', 'stream', ['field' => 'f', 'value' => 'v'], null);

        self::assertSame('hello', $this->redis->get('s'));
        self::assertTrue($this->redis->ttl('s') > 0);
        self::assertSame(['f' => 'v'], $this->redis->hGetAll('h'));
        self::assertSame(1.5, $this->redis->zScore('z', 'm'));
        self::assertSame(1, $this->redis->xLen('x'));

        self::assertThrows(UserError::class, fn () => $this->keys->create('s', 'string', ['value' => 'again'], null), 'already exists');
        self::assertThrows(UserError::class, fn () => $this->keys->create('n', 'zset', ['member' => 'm', 'score' => 'abc'], null), 'number');
    }

    public function testInspectsStringsWithFormatAndTruncation(): void
    {
        $this->redis->set('json', '{"a":1}');
        $this->redis->set('long', str_repeat('x', 40));

        $json = $this->keys->inspect('json');
        self::assertSame('json', $json['format']);
        self::assertSame(false, $json['truncated']);

        $long = $this->keys->inspect('long');
        self::assertSame(true, $long['truncated']);
        self::assertSame(str_repeat('x', 16), $long['value']);
        self::assertSame(40, $long['length']);

        self::assertThrows(UserError::class, fn () => $this->keys->inspect('missing'), 'no longer exists');
    }

    public function testStringSaveKeepsTheTtl(): void
    {
        $this->redis->setex('s', 500, 'old');
        $this->keys->setString('s', 'new');

        self::assertSame('new', $this->redis->get('s'));
        self::assertTrue($this->redis->ttl('s') > 400);
    }

    public function testRenamesHashFieldsAtomically(): void
    {
        $this->redis->hSet('h', 'old', '1');
        $this->keys->setHashField('h', 'old', 'new', '2');

        self::assertSame(['new' => '2'], $this->redis->hGetAll('h'));
    }

    public function testListEditsRefuseAStaleIndex(): void
    {
        $this->redis->rPush('l', 'a', 'b', 'c');
        $items = $this->keys->inspect('l')['items'];

        $this->keys->setListItem('l', 1, $items[1]['hash'], 'B');
        self::assertSame(['a', 'B', 'c'], $this->redis->lRange('l', 0, -1));

        // Someone else pushes to the head: index 0 no longer holds "a".
        $this->redis->lPush('l', 'z');
        self::assertThrows(UserError::class, fn () => $this->keys->deleteListItem('l', 0, $items[0]['hash']), 'changed');

        $this->keys->deleteListItem('l', 1, sha1('a'));
        self::assertSame(['z', 'B', 'c'], $this->redis->lRange('l', 0, -1));
    }

    public function testReplacesSetAndSortedSetMembers(): void
    {
        $this->redis->sAdd('s', 'a');
        $this->keys->setSetMember('s', 'a', 'b');
        self::assertSame(['b'], $this->redis->sMembers('s'));

        $this->redis->zAdd('z', 1, 'a');
        $this->keys->setZsetMember('z', 'a', 'b', '7');
        self::assertSame(['b' => 7.0], $this->redis->zRange('z', 0, -1, true));
    }

    public function testEditRefusesAKeyThatChangedType(): void
    {
        $this->redis->set('k', 'string now');

        self::assertThrows(UserError::class, fn () => $this->keys->setHashField('k', 'f', 'f', 'v'), 'string, not a hash');
    }

    public function testCollectionItemsAreTruncatedButMembersAreNot(): void
    {
        $this->redis->hSet('h', 'field', str_repeat('v', 20));
        $this->redis->sAdd('s', str_repeat('m', 20));

        $hash = $this->keys->inspect('h')['items'][0];
        self::assertSame(true, $hash['truncated']);
        self::assertSame('vvvvvvvv', $hash['value']);

        self::assertSame(str_repeat('m', 20), $this->keys->inspect('s')['items'][0]['member']);
    }

    public function testReadsStreamsNewestFirstWithPaging(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->redis->xAdd('x', "$i-0", ['n' => (string) $i]);
        }

        $first = $this->keys->inspect('x', ['count' => 2]);
        self::assertSame(['5-0', '4-0'], array_column($first['items'], 'id'));

        $next = $this->keys->inspect('x', ['count' => 2, 'cursor' => $first['cursor']]);
        self::assertSame(['3-0', '2-0'], array_column($next['items'], 'id'));
    }

    public function testRenameExpireAndDelete(): void
    {
        $this->redis->set('a', '1');
        $this->redis->set('b', '2');

        self::assertThrows(UserError::class, fn () => $this->keys->rename('a', 'b', false), 'already exists');

        $this->keys->rename('a', 'c', false);
        self::assertSame('1', $this->redis->get('c'));

        $this->keys->expire('c', 100);
        self::assertTrue($this->redis->ttl('c') > 0);
        $this->keys->expire('c', null);
        self::assertSame(-1, $this->redis->ttl('c'));

        self::assertSame(2, $this->keys->delete(['b', 'c', 'missing']));
    }

    public function testDeleteMatchingOnlyTouchesTheMatch(): void
    {
        for ($i = 0; $i < 2500; $i++) {
            $this->redis->set("cache:$i", 'x');
        }

        $this->redis->set('keep', 'x');

        $cursor = '0';
        $deleted = 0;

        do {
            $step = $this->keys->deleteMatching('cache:*', null, $cursor, 10);
            $deleted += $step['deleted'];
            $cursor = $step['cursor'];
        } while (! $step['done']);

        self::assertSame(2500, $deleted);
        self::assertSame(1, $this->redis->dbSize());
    }

    public function testWorksForARestrictedAclUserAndReportsWhatItMayNotDo(): void
    {
        $user = 'redis-admin-test-'.bin2hex(random_bytes(4));
        $this->redis->rawCommand('ACL', 'SETUSER', $user, 'on', '>secret', '~*', '&*', '+@all', '-@admin', '-@dangerous', '-memory');

        try {
            $target = $this->redisTarget();
            $restricted = new \Redis;
            $target['socket'] !== null ? $restricted->connect($target['socket']) : $restricted->connect($target['host'], $target['port']);
            $restricted->auth([$user, 'secret']);
            $restricted->select($target['db']);

            $client = new \RedisAdmin\Client($restricted);
            $keys = new Keys($client, new Formatter);

            $keys->create('k', 'string', ['value' => 'v'], null);
            $listed = $keys->scan('*', null, '0', 10)['keys'];

            self::assertSame(['k'], array_column($listed, 'label'));
            self::assertSame(null, $listed[0]['memory'], 'MEMORY USAGE is denied, so memory is unknown rather than an error.');
            self::assertThrows(UserError::class, fn () => $client->call('FLUSHALL'), 'Not permitted');
        } finally {
            $this->redis->rawCommand('ACL', 'DELUSER', $user);
        }
    }

    public function testBinaryKeysSurviveTheRoundTrip(): void
    {
        $key = "bin\xff\x00key";
        $this->redis->set($key, "\x01\x02");

        $listed = $this->keys->scan('bin*', null, '0', 10)['keys'][0];
        self::assertSame(['$b64' => base64_encode($key)], $listed['key']);
        self::assertSame($key, Codec::fromId($listed['id']));

        $detail = $this->keys->inspect(Codec::fromId($listed['id']));
        self::assertSame('binary', $detail['format']);
        self::assertSame(['$b64' => base64_encode("\x01\x02")], $detail['value']);
    }
}
