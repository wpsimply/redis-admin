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
        if ($complete && $this->decodeSerialized && ($pretty = $this->unserialize($bytes)) !== null) {
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
     * Decode a PHP-serialized value for display, never instantiating a class.
     */
    private function unserialize(string $bytes): ?string
    {
        if (preg_match('/^(?:[aOCE]:\d+:|s:\d+:"|i:-?\d+;|d:|b:[01];|N;)/', $bytes) !== 1) {
            return null;
        }

        $value = @unserialize($bytes, ['allowed_classes' => false, 'max_depth' => 64]);

        if ($value === false && $bytes !== 'b:0;') {
            return null;
        }

        return json_encode(
            $this->normalize($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ) ?: null;
    }

    /**
     * Reduce an unserialized value to something JSON can show: objects become
     * arrays tagged with their class, private and protected property names
     * lose their mangling, and binary strings are escaped.
     */
    private function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 64) {
            return '…';
        }

        if (is_string($value)) {
            return Codec::display($value);
        }

        if (is_float($value) && ! is_finite($value)) {
            return (string) $value;
        }

        if (is_object($value)) {
            $properties = (array) $value;
            $class = $properties['__PHP_Incomplete_Class_Name'] ?? $value::class;
            unset($properties['__PHP_Incomplete_Class_Name']);

            $normalized = ['__class' => $class];

            foreach ($properties as $name => $property) {
                $clean = str_contains((string) $name, "\0") ? substr((string) $name, strrpos((string) $name, "\0") + 1) : (string) $name;
                $normalized[$clean] = $this->normalize($property, $depth + 1);
            }

            return $normalized;
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[is_string($key) ? Codec::display($key) : $key] = $this->normalize($item, $depth + 1);
            }

            return $normalized;
        }

        return $value;
    }
}
