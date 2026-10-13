<?php

declare(strict_types=1);

namespace Vortech\Trace;

/**
 * Turns the attributes of a feed into local columns: what to copy, how to find an existing
 * record the first time, and a last chance to normalise the values.
 */
abstract class Mapper
{
    /**
     * Whitelist of remote attributes. `'name'` keeps the name, `'barcode' => 'ean'` renames it.
     *
     * @return array<int|string, string>
     */
    abstract public function fields(): array;

    /**
     * Remote attribute => local column that identify an existing record before it has a link.
     *
     * @return array<string, string>
     */
    public function match(): array
    {
        return [];
    }

    /**
     * Receives local column names and may change values or drop columns, never add one.
     *
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    public function transform(array $mapped): array
    {
        return $mapped;
    }

    /**
     * remote attribute => local column, normalised.
     *
     * @return array<string, string>
     */
    final public function mapping(): array
    {
        $mapping = [];

        foreach ($this->fields() as $remote => $local) {
            $mapping[is_int($remote) ? $local : $remote] = $local;
        }

        return $mapping;
    }

    /**
     * @return list<string>
     */
    final public function localColumns(): array
    {
        return array_values(array_unique(array_values($this->mapping())));
    }
}
