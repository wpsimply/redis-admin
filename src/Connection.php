<?php

declare(strict_types=1);

namespace RedisSimply;

use Redis;
use RedisException;

/**
 * Opens the Redis connection a grant allows.
 *
 * The socket path is built from the configured template and the grant's
 * target, which {@see TokenStore::validate()} has already restricted to a safe
 * character set. Nothing from the request is ever part of it.
 */
final class Connection
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param  array{target: string, user: ?string, password: ?string, db: int}  $grant
     */
    public function open(array $grant): Redis
    {
        $redis = new Redis;

        try {
            $socket = $this->socketFor($grant['target']);

            $socket !== null
                ? $redis->connect($socket, 0, (float) $this->config->get('redis.timeout'))
                : $redis->connect(
                    (string) $this->config->get('redis.host'),
                    $this->config->int('redis.port'),
                    (float) $this->config->get('redis.timeout'),
                );

            $redis->setOption(Redis::OPT_READ_TIMEOUT, (float) $this->config->get('redis.read_timeout'));
            $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);

            if ($grant['password'] !== null) {
                $redis->auth($grant['user'] !== null ? [$grant['user'], $grant['password']] : $grant['password']);
            }

            if ($grant['db'] !== 0) {
                $redis->select($grant['db']);
            }
        } catch (RedisException $e) {
            throw new UserError('Could not connect to Redis: '.$e->getMessage(), 503);
        }

        return $redis;
    }

    /**
     * The socket path for a target, or null when connecting over TCP.
     */
    public function socketFor(string $target): ?string
    {
        $template = $this->config->get('redis.socket');

        if (! is_string($template) || $template === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $target) !== 1) {
            throw new UserError('Invalid target.', 403);
        }

        return str_replace('{target}', $target, $template);
    }
}
