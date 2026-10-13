<?php

declare(strict_types=1);

namespace Vortech\Trace\Support;

use Stringable;
use Throwable;
use Vortech\Trace\Exceptions\ProtocolException;

/** Validating narrowing of decoded JSON and database values. */
final readonly class Payload
{
    /**
     * @throws ProtocolException
     * @throws Throwable
     */
    public static function string(mixed $value, string $what = 'value'): string
    {
        throw_unless(
            condition: is_string($value) || is_int($value) || $value instanceof Stringable,
            exception: new ProtocolException("Expected {$what} to be a string, got ".get_debug_type($value).'.', 'invalid_payload'),
        );

        return (string) $value;
    }

    /**
     * @throws ProtocolException
     * @throws Throwable
     */
    public static function stringOrNull(mixed $value, string $what = 'value'): ?string
    {
        return $value === null ? null : self::string($value, $what);
    }

    /**
     * @throws ProtocolException
     * @throws Throwable
     */
    public static function int(mixed $value, string $what = 'value'): int
    {
        throw_unless(
            condition: is_int($value) || (is_string($value) && ctype_digit($value)),
            exception: new ProtocolException("Expected {$what} to be an integer, got ".get_debug_type($value).'.', 'invalid_payload'),
        );

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ProtocolException
     * @throws Throwable
     */
    public static function map(mixed $value, string $what = 'value'): array
    {
        throw_unless(
            condition: is_array($value),
            exception: new ProtocolException("Expected {$what} to be an object, got ".get_debug_type($value).'.', 'invalid_payload'),
        );

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @return list<mixed>
     *
     * @throws ProtocolException
     * @throws Throwable
     */
    public static function list(mixed $value, string $what = 'value'): array
    {
        throw_unless(
            condition: is_array($value) && array_is_list($value),
            exception: new ProtocolException("Expected {$what} to be a list, got ".get_debug_type($value).'.', 'invalid_payload'),
        );

        return $value;
    }
}
