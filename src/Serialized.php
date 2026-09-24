<?php

declare(strict_types=1);

namespace RedisSimply;

/**
 * Reads PHP-serialized values for display without unserialize().
 *
 * unserialize() is not meant for data from anywhere else, and a Redis
 * instance holds whatever anyone managed to put in it -- WordPress's object
 * cache among others. This walks the serialized format itself instead:
 * nothing is ever instantiated or resolved.
 */
final class Serialized
{
    /**
     * How deeply arrays and objects may nest in a value read for display.
     */
    private const int MAX_READ_DEPTH = 64;

    /**
     * How serialized data starts, before it is worth parsing.
     */
    private const string START = '/^(?:[aOCE]:\d+:|s:\d+:"|i:-?\d+;|d:|b:[01];|N;)/';

    private const string FLOAT = '/\Gd:(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?|INF|-INF|NAN);/';

    private int $position = 0;

    private function __construct(private readonly string $data) {}

    /**
     * A serialized value as plain data for display, or null when it is not
     * complete, well-formed serialized data. It is wrapped in a one-element
     * list, so that a serialized false or null can be told from a failure.
     *
     * Nothing is instantiated or resolved: an object becomes an array tagged
     * with its class ("__class"), its private and protected property names
     * lose their mangling, a reference is shown as one, and a string that is
     * not text comes back with its odd bytes escaped.
     *
     * @return array{0: mixed}|null
     */
    public static function decode(string $value): ?array
    {
        if (preg_match(self::START, $value) !== 1) {
            return null;
        }

        $parser = new self($value);

        try {
            $decoded = $parser->read(0);
        } catch (\UnexpectedValueException) {
            return null;
        }

        return $parser->position === strlen($value) ? [$decoded] : null;
    }

    /**
     * Read one value for display. See {@see self::decode()}.
     */
    private function read(int $level): mixed
    {
        if ($level > self::MAX_READ_DEPTH) {
            throw new \UnexpectedValueException('Nested too deeply.');
        }

        $type = $this->data[$this->position] ?? '';

        return match ($type) {
            'N' => $this->readNull(),
            'b' => $this->scalar('/\Gb:[01];/') === 'b:1;',
            'i' => self::integer(substr($this->scalar('/\Gi:[+-]?\d+;/'), 2, -1)),
            'd' => self::float(substr($this->scalar(self::FLOAT), 2, -1)),
            'r', 'R' => sprintf('(reference to value %s)', substr($this->scalar('/\G[rR]:\d+;/'), 2, -1)),
            's' => $this->readString(),
            'a' => $this->readArray($level),
            'O' => $this->readObject($level),
            'C' => $this->readCustom(),
            'E' => ['__enum' => Codec::display($this->readEnum())],
            default => throw new \UnexpectedValueException('Unknown type.'),
        };
    }

    private function readNull(): null
    {
        $this->expect('N;');

        return null;
    }

    private function readString(): string
    {
        $bytes = $this->lengthPrefixed('s');
        $this->expect(';');

        return Codec::display($bytes);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function readArray(int $level): array
    {
        $count = $this->count('a');
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $key = $this->readKey();
            $out[is_string($key) ? Codec::display($key) : $key] = $this->read($level + 1);
        }

        $this->expect('}');

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function readObject(int $level): array
    {
        $class = $this->lengthPrefixed('O');
        $this->expect(':');
        $count = $this->number();
        $this->expect(':{');
        $out = ['__class' => Codec::display($class)];

        for ($i = 0; $i < $count; $i++) {
            $name = (string) $this->readKey();

            // Private and protected names are mangled: "\0Class\0name", "\0*\0name".
            if (str_contains($name, "\0")) {
                $name = substr($name, strrpos($name, "\0") + 1);
            }

            $out[Codec::display($name)] = $this->read($level + 1);
        }

        $this->expect('}');

        return $out;
    }

    /**
     * An object serialized by its own Serializable::serialize(): only its
     * class knows the payload's format, so it is shown as it is.
     *
     * @return array{__class: string, __data: string}
     */
    private function readCustom(): array
    {
        $class = $this->lengthPrefixed('C');
        $this->expect(':');
        $length = $this->number();
        $this->expect(':{');
        $payload = $this->take($length);
        $this->expect('}');

        return ['__class' => Codec::display($class), '__data' => Codec::display($payload)];
    }

    private function readEnum(): string
    {
        $name = $this->lengthPrefixed('E');
        $this->expect(';');

        return $name;
    }

    private function readKey(): int|string
    {
        $type = $this->data[$this->position] ?? '';

        if ($type === 'i') {
            return (int) substr($this->scalar('/\Gi:[+-]?\d+;/'), 2, -1);
        }

        if ($type === 's') {
            $bytes = $this->lengthPrefixed('s');
            $this->expect(';');

            return $bytes;
        }

        throw new \UnexpectedValueException('Invalid key.');
    }

    /**
     * An integer, or its digits as they are when it does not fit in one.
     */
    private static function integer(string $digits): int|string
    {
        $int = filter_var($digits, FILTER_VALIDATE_INT);

        return $int === false ? $digits : $int;
    }

    /**
     * A float; infinity and NaN, which JSON cannot hold, as their names.
     */
    private static function float(string $text): float|string
    {
        return in_array($text, ['INF', '-INF', 'NAN'], true) ? $text : (float) $text;
    }

    /**
     * T:<length>:"<bytes>" -- returns the bytes.
     */
    private function lengthPrefixed(string $type): string
    {
        $length = $this->count($type, ':"');
        $bytes = $this->take($length);
        $this->expect('"');

        return $bytes;
    }

    /**
     * T:<n> followed by the given delimiter -- returns n.
     */
    private function count(string $type, string $then = ':{'): int
    {
        $this->expect($type.':');
        $number = $this->number();
        $this->expect($then);

        return $number;
    }

    private function number(): int
    {
        if (preg_match('/\G\d{1,10}/', $this->data, $match, 0, $this->position) !== 1) {
            throw new \UnexpectedValueException('Expected a number.');
        }

        $this->position += strlen($match[0]);

        return (int) $match[0];
    }

    private function take(int $length): string
    {
        if ($this->position + $length > strlen($this->data)) {
            throw new \UnexpectedValueException('Truncated.');
        }

        $bytes = substr($this->data, $this->position, $length);
        $this->position += $length;

        return $bytes;
    }

    private function scalar(string $pattern): string
    {
        if (preg_match($pattern, $this->data, $match, 0, $this->position) !== 1) {
            throw new \UnexpectedValueException('Invalid scalar.');
        }

        $this->position += strlen($match[0]);

        return $match[0];
    }

    private function expect(string $text): void
    {
        if ($this->position + strlen($text) > strlen($this->data) || substr_compare($this->data, $text, $this->position, strlen($text)) !== 0) {
            throw new \UnexpectedValueException('Expected '.$text);
        }

        $this->position += strlen($text);
    }
}
