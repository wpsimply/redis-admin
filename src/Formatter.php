<?php

declare(strict_types=1);

namespace RedisSimply;

/**
 * Recognises what a string value holds and renders a readable version of it.
 *
 * Detection only ever feeds the display: the stored bytes are what is edited
 * and saved, whatever format they were recognised as.
 */
final class Formatter
{
    public function __construct(private readonly bool $decodeSerialized = true) {}

    /**
     * @return array{format: 'json'|'serialized'|'text'|'binary', pretty: ?string}
     */
    public function describe(string $bytes, bool $complete = true): array
    {
        // Checked before the binary test: an object with private or protected
        // properties serializes their names with NUL bytes, and WordPress's
        // object cache is full of them.
        if ($complete && $this->decodeSerialized && ($pretty = $this->serialized($bytes)) !== null) {
            return ['format' => 'serialized', 'pretty' => $pretty];
        }

        if (! Codec::isText($bytes)) {
            return ['format' => 'binary', 'pretty' => null];
        }

        if (! $complete) {
            return ['format' => 'text', 'pretty' => null];
        }

        $trimmed = ltrim($bytes);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[') && json_validate($bytes)) {
            return [
                'format' => 'json',
                'pretty' => json_encode(json_decode($bytes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: null,
            ];
        }

        return ['format' => 'text', 'pretty' => null];
    }

    /**
     * Decode a PHP-serialized value for display. It is read by
     * {@see Serialized}, never by unserialize(): values come from Redis, and
     * nothing in them is instantiated or trusted.
     */
    private function serialized(string $bytes): ?string
    {
        $decoded = Serialized::decode($bytes);

        if ($decoded === null) {
            return null;
        }

        return json_encode(
            $decoded[0],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ) ?: null;
    }
}
