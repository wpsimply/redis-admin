<?php

declare(strict_types=1);

namespace RedisAdmin;

/**
 * The JSON API behind the UI: one action per request.
 *
 * Reads are GET, changes are POST with a JSON body and the session's CSRF
 * token in the X-CSRF-Token header. Every action runs against the instance the
 * session was signed in to, and only that one.
 */
final class Api
{
    private const array READS = ['session', 'info', 'scan', 'key'];

    private ?Client $client = null;

    public function __construct(
        private readonly Config $config,
        private readonly Session $session,
        private readonly Connection $connection,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function handle(string $method, string $action, array $query, array $body, ?string $csrfToken = null): array
    {
        $grant = $this->session->grant();

        if ($grant === null) {
            throw new UserError('Your session has ended. Open Redis Admin again from your control panel.', 401);
        }

        $isRead = in_array($action, self::READS, true);

        if ($isRead && $method !== 'GET') {
            throw new UserError('Method not allowed.', 405);
        }

        if (! $isRead) {
            if ($method !== 'POST') {
                throw new UserError('Method not allowed.', 405);
            }

            if (! $this->session->verifyCsrf($csrfToken)) {
                throw new UserError('Your session token is out of date. Reload the page.', 419);
            }
        }

        if ($action === 'select-db') {
            $db = (int) ($body['db'] ?? 0);

            if ($db < 0 || $db >= $this->config->int('redis.databases')) {
                throw new UserError('Unknown database.');
            }

            $this->session->selectDatabase($db);
            $grant['db'] = $db;
        }

        $this->session->release();

        return match ($action) {
            'session' => $this->sessionPayload($grant),
            'info' => $this->info($grant)->summary($grant['db']),
            'scan' => $this->keys($grant)->scan(
                (string) ($query['pattern'] ?? '*'),
                $this->optionalType($query['type'] ?? null),
                (string) ($query['cursor'] ?? '0'),
                max(1, min(1000, (int) ($query['count'] ?? $this->config->int('limits.scan_page')))),
            ),
            'key' => $this->keys($grant)->inspect(Codec::fromId($query['id'] ?? null), [
                'cursor' => (string) ($query['cursor'] ?? '0'),
                'offset' => (int) ($query['offset'] ?? 0),
                'count' => (int) ($query['count'] ?? 100),
            ]),
            'select-db' => $this->sessionPayload($grant),
            default => $this->mutate($grant, $action, $body),
        };
    }

    /**
     * @param  array<string, mixed>  $grant
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function mutate(array $grant, string $action, array $body): array
    {
        $keys = $this->keys($grant);
        $key = fn (): string => Codec::fromId($body['id'] ?? null);
        $bytes = fn (string $name): string => Codec::decode($body[$name] ?? null);
        $optional = fn (string $name): ?string => array_key_exists($name, $body) && $body[$name] !== null ? Codec::decode($body[$name]) : null;

        switch ($action) {
            case 'create':
                $name = $bytes('key');
                $keys->create($name, (string) ($body['type'] ?? ''), $body, $this->optionalTtl($body['ttl'] ?? null));

                return ['id' => Codec::id($name)];

            case 'string.set':
                $keys->setString($key(), $bytes('value'));
                break;

            case 'hash.set':
                $keys->setHashField($key(), $optional('original'), $bytes('field'), $bytes('value'));
                break;

            case 'hash.delete':
                $keys->deleteHashField($key(), $bytes('field'));
                break;

            case 'list.push':
                $keys->pushList($key(), $bytes('value'), ($body['side'] ?? 'tail') === 'head');
                break;

            case 'list.set':
                $keys->setListItem($key(), (int) ($body['index'] ?? 0), (string) ($body['hash'] ?? ''), $bytes('value'));
                break;

            case 'list.delete':
                $keys->deleteListItem($key(), (int) ($body['index'] ?? 0), (string) ($body['hash'] ?? ''));
                break;

            case 'set.set':
                $keys->setSetMember($key(), $optional('original'), $bytes('member'));
                break;

            case 'set.delete':
                $keys->deleteSetMember($key(), $bytes('member'));
                break;

            case 'zset.set':
                $keys->setZsetMember($key(), $optional('original'), $bytes('member'), $body['score'] ?? null);
                break;

            case 'zset.delete':
                $keys->deleteZsetMember($key(), $bytes('member'));
                break;

            case 'stream.add':
                $fields = [];

                foreach ((array) ($body['fields'] ?? []) as $pair) {
                    if (is_array($pair) && Codec::decode($pair['field'] ?? '') !== '') {
                        $fields[] = [Codec::decode($pair['field']), Codec::decode($pair['value'] ?? '')];
                    }
                }

                return ['entry' => $keys->addStreamEntry($key(), $fields)];

            case 'stream.delete':
                $keys->deleteStreamEntry($key(), (string) ($body['entry'] ?? ''));
                break;

            case 'rename':
                $newKey = $bytes('newKey');
                $keys->rename($key(), $newKey, (bool) ($body['overwrite'] ?? false));

                return ['id' => Codec::id($newKey)];

            case 'expire':
                $keys->expire($key(), $this->optionalTtl($body['ttl'] ?? null));
                break;

            case 'delete':
                $ids = $body['ids'] ?? null;

                if (! is_array($ids) || $ids === [] || count($ids) > 5000) {
                    throw new UserError('Select between 1 and 5000 keys.');
                }

                return ['deleted' => $keys->delete(array_map(Codec::fromId(...), array_values($ids)))];

            case 'delete-matching':
                return $keys->deleteMatching(
                    (string) ($body['pattern'] ?? ''),
                    $this->optionalType($body['type'] ?? null),
                    (string) ($body['cursor'] ?? '0'),
                    (float) $this->config->get('limits.bulk_budget'),
                );

            default:
                throw new UserError('Unknown action.', 404);
        }

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $grant
     * @return array<string, mixed>
     */
    private function sessionPayload(array $grant): array
    {
        return [
            'label' => $grant['label'],
            'db' => $grant['db'],
            'databases' => $this->config->int('redis.databases'),
            'prefix' => $grant['prefix'],
            'limits' => [
                'scan_page' => $this->config->int('limits.scan_page'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $grant
     */
    public function client(array $grant): Client
    {
        return $this->client ??= new Client($this->connection->open($grant));
    }

    /**
     * @param  array<string, mixed>  $grant
     */
    private function keys(array $grant): Keys
    {
        return new Keys(
            $this->client($grant),
            new Formatter((bool) $this->config->get('decode_serialized')),
            $this->config->int('limits.string_preview'),
            $this->config->int('limits.item_preview'),
        );
    }

    /**
     * @param  array<string, mixed>  $grant
     */
    private function info(array $grant): ServerInfo
    {
        return new ServerInfo($this->client($grant), $this->config->int('redis.databases'));
    }

    private function optionalType(mixed $type): ?string
    {
        return is_string($type) && $type !== '' ? $type : null;
    }

    private function optionalTtl(mixed $ttl): ?int
    {
        if ($ttl === null || $ttl === '') {
            return null;
        }

        if (! is_numeric($ttl)) {
            throw new UserError('The TTL must be a whole number of seconds.');
        }

        return (int) $ttl;
    }
}
