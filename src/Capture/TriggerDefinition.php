<?php

declare(strict_types=1);

namespace Vortech\Trace\Capture;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use JsonException;

/** Everything a capture trigger depends on: when one of these changes, the trigger is outdated. */
final readonly class TriggerDefinition
{
    /** Bump it when the generated SQL changes, so installed triggers are reported as outdated. */
    private const int FORMAT = 1;

    /**
     * @param  class-string  $modelClass
     */
    public function __construct(
        public Connection $connection,
        public string $modelClass,
        public string $modelType,
        public string $table,
        public string $keyColumn,
        public ?string $softDeleteColumn,
    ) {}

    /** The table name as the database knows it: prefix included, schema left out. */
    public function physicalTable(): string
    {
        return $this->connection->getTablePrefix().Str::afterLast($this->table, '.');
    }

    public function triggerName(string $suffix): string
    {
        return 'trace_'.substr($this->physicalTable(), 0, 40).'_'.$suffix;
    }

    public function metaKey(): string
    {
        return 'trigger.'.$this->physicalTable();
    }

    /**
     * @throws JsonException
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            self::FORMAT,
            $this->connection->getDriverName(),
            $this->table,
            $this->keyColumn,
            $this->softDeleteColumn,
            $this->modelType,
        ], JSON_THROW_ON_ERROR));
    }
}
