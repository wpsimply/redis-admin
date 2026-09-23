<?php

declare(strict_types=1);

namespace RedisSimply;

/**
 * Carries Redis's binary-safe strings through JSON.
 *
 * Redis keys and values are byte strings, JSON strings are UTF-8. A value that
 * is valid UTF-8 travels as a plain JSON string; anything else travels as
 * {"$b64": "..."}. The same shape is used by the API, the export files and the
 * import, so a binary key survives every round trip untouched.
 */
final class Codec
{
    /**
     * Encode bytes for JSON.
     *
     * @return string|array{'$b64': string}
     */
    public static function encode(string $bytes): string|array
    {
        return self::isText($bytes) ? $bytes : ['$b64' => base64_encode($bytes)];
    }

    /**
     * Whether bytes are text a person can read and edit: valid UTF-8 with no
     * control characters other than tab, newline and carriage return.
     */
    public static function isText(string $bytes): bool
    {
        return mb_check_encoding($bytes, 'UTF-8') && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $bytes) !== 1;
    }

    /**
     * Decode a JSON value produced by {@see self::encode()} back to bytes.
     */
    public static function decode(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value) && isset($value['$b64']) && is_string($value['$b64'])) {
            $bytes = base64_decode($value['$b64'], true);

            if ($bytes === false) {
                throw new UserError('Invalid base64 value.');
            }

            return $bytes;
        }

        throw new UserError('Expected a string value.');
    }

    /**
     * The opaque id the browser uses to refer to a key: URL-safe base64 of the
     * raw bytes, so it is exact whatever the key contains.
     */
    public static function id(string $key): string
    {
        return rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
    }

    /**
     * Resolve an id back to the key's bytes.
     */
    public static function fromId(mixed $id): string
    {
        if (! is_string($id) || $id === '' || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
            throw new UserError('Invalid key reference.');
        }

        $bytes = base64_decode(strtr($id, '-_', '+/'), true);

        if ($bytes === false) {
            throw new UserError('Invalid key reference.');
        }

        return $bytes;
    }

    /**
     * A printable rendering of any bytes, for labels: control characters and
     * invalid UTF-8 sequences become \xHH escapes.
     */
    public static function display(string $bytes): string
    {
        return self::isText($bytes) ? $bytes : self::escapeInvalid($bytes);
    }

    /**
     * Escape only the bytes that are not part of a valid UTF-8 sequence.
     */
    private static function escapeInvalid(string $bytes): string
    {
        $out = '';
        $length = strlen($bytes);
        $i = 0;

        while ($i < $length) {
            $char = self::utf8CharAt($bytes, $i);

            if ($char === null || ($char !== "\t" && $char !== "\n" && $char !== "\r" && (ord($char) < 0x20 || $char === "\x7f"))) {
                $out .= sprintf('\\x%02x', ord($bytes[$i]));
                $i++;

                continue;
            }

            $out .= $char;
            $i += strlen($char);
        }

        return $out;
    }

    /**
     * The valid UTF-8 character starting at the given offset, if there is one.
     */
    private static function utf8CharAt(string $bytes, int $offset): ?string
    {
        $first = ord($bytes[$offset]);
        $width = match (true) {
            $first < 0x80 => 1,
            $first >= 0xC2 && $first <= 0xDF => 2,
            $first >= 0xE0 && $first <= 0xEF => 3,
            $first >= 0xF0 && $first <= 0xF4 => 4,
            default => 0,
        };

        if ($width === 0) {
            return null;
        }

        $char = substr($bytes, $offset, $width);

        return strlen($char) === $width && mb_check_encoding($char, 'UTF-8') ? $char : null;
    }
}
