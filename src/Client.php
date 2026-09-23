<?php

declare(strict_types=1);

namespace RedisSimply;

use Redis;
use RedisException;

/**
 * A thin layer over phpredis that speaks raw commands and fails loudly.
 *
 * Everything goes through rawCommand with literal replies, so a status reply
 * reads as the string Redis sent ("OK", "string") rather than `true`, and a
 * command either returns its reply or throws. phpredis itself reports a
 * command error by returning false and leaving the message in getLastError(),
 * which is indistinguishable from a nil reply unless it is checked every time.
 */
final class Client
{
    public function __construct(public readonly Redis $redis)
    {
        $redis->setOption(Redis::OPT_REPLY_LITERAL, true);
    }

    /**
     * Run one command and return its reply; a nil reply comes back as null.
     */
    public function call(string|int|float ...$args): mixed
    {
        $this->redis->clearLastError();

        try {
            $reply = $this->redis->rawCommand(...array_map('strval', $args));
        } catch (RedisException $e) {
            // phpredis throws for some error replies (NOPERM among them) as
            // well as for a broken connection; only the latter is a 502.
            if (preg_match('/^[A-Z]{2,}\s/', $e->getMessage()) === 1) {
                throw new UserError(self::describe($e->getMessage()));
            }

            throw new UserError('Redis connection error: '.$e->getMessage(), 502);
        }

        if ($reply === false) {
            $error = $this->redis->getLastError();

            if ($error !== null) {
                throw new UserError(self::describe($error));
            }

            return null;
        }

        return $reply;
    }

    /**
     * Run a batch of commands inside MULTI/EXEC and return their replies.
     *
     * @param  list<list<string|int|float>>  $commands
     * @return list<mixed>
     */
    public function transaction(array $commands): array
    {
        if ($commands === []) {
            return [];
        }

        $this->call('MULTI');

        try {
            foreach ($commands as $command) {
                $this->call(...$command);
            }
        } catch (UserError $e) {
            $this->redis->clearLastError();
            $this->redis->rawCommand('DISCARD');

            throw $e;
        }

        $replies = $this->call('EXEC');

        if (! is_array($replies)) {
            throw new UserError('The change was not applied: the transaction was aborted.');
        }

        foreach ($replies as $reply) {
            if ($reply === false && ($error = $this->redis->getLastError()) !== null) {
                throw new UserError(self::describe($error));
            }
        }

        return array_values($replies);
    }

    /**
     * Turn a Redis error into a message worth showing.
     */
    public static function describe(string $error): string
    {
        if (str_starts_with($error, 'NOPERM')) {
            return 'Not permitted: '.trim(substr($error, 6));
        }

        if (str_starts_with($error, 'WRONGTYPE')) {
            return 'The key holds a different type of value than this operation expects.';
        }

        if (str_starts_with($error, 'OOM')) {
            return 'Redis is out of memory and refused the write.';
        }

        return preg_replace('/^ERR\s+/', '', $error) ?? $error;
    }
}
