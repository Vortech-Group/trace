<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Throwable;
use Vortech\Trace\Exceptions\InvalidMappingException;
use Vortech\Trace\Exceptions\SchemaMismatchException;
use Vortech\Trace\Mapper;

/** Applies a Mapper to the attributes of one record. */
final readonly class AttributeMapper
{
    /**
     * Local column => value for every whitelisted attribute. A missing attribute is a schema
     * error, a `null` value is data.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     *
     * @throws SchemaMismatchException
     * @throws InvalidMappingException
     * @throws Throwable
     */
    public function map(Mapper $mapper, array $attributes): array
    {
        $mapped = [];

        foreach ($mapper->mapping() as $remote => $local) {
            throw_unless(
                condition: array_key_exists($remote, $attributes),
                exception: new SchemaMismatchException("The attribute [{$remote}] is missing from the feed payload."),
            );

            $mapped[$local] = $attributes[$remote];
        }

        $transformed = $mapper->transform($mapped);
        $allowed = $mapper->localColumns();

        foreach (array_keys($transformed) as $column) {
            throw_unless(
                condition: in_array($column, $allowed, true),
                exception: new InvalidMappingException('transform() of '.$mapper::class." returned [{$column}], which is not in fields()."),
            );
        }

        return $transformed;
    }

    /**
     * Local column => remote value used to find an existing record. Null values cannot identify anything.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     *
     * @throws SchemaMismatchException
     * @throws Throwable
     */
    public function matchValues(Mapper $mapper, array $attributes): array
    {
        $values = [];

        foreach ($mapper->match() as $remote => $local) {
            throw_unless(
                condition: array_key_exists($remote, $attributes),
                exception: new SchemaMismatchException("The match attribute [{$remote}] is not published by the source."),
            );

            if ($attributes[$remote] !== null) {
                $values[$local] = $attributes[$remote];
            }
        }

        return $values;
    }
}
