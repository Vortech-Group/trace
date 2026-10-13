<?php

declare(strict_types=1);

namespace Vortech\Trace\Capture;

/**
 * Writes the change log from inside the database: lock the stream row, bump its version,
 * insert the change. It is the SQL twin of StreamSequencer and ChangeRecorder, so a trigger
 * and the Eloquent listener produce the same log and the same commit order.
 *
 * The default implementation creates one trigger per event, which MySQL and SQLite need.
 */
abstract class TriggerDialect
{
    /**
     * @return list<string>
     */
    public function create(TriggerDefinition $definition): array
    {
        $table = $this->table($definition);
        $key = $this->wrap($definition, $definition->keyColumn);
        $statements = [];

        $events = [
            'ai' => ['INSERT', 'NEW', "'changed'"],
            'au' => ['UPDATE', 'NEW', $this->updateOperation($definition)],
            'ad' => ['DELETE', 'OLD', "'deleted'"],
        ];

        foreach ($events as $suffix => [$event, $row, $operation]) {
            $body = implode(' ', array_map(fn (string $sql): string => $sql.';', $this->log(
                $definition,
                $this->cast("{$row}.{$key}"),
                $operation,
            )));

            $statements[] = sprintf(
                'CREATE TRIGGER %s AFTER %s ON %s FOR EACH ROW BEGIN %s END',
                $this->wrap($definition, $definition->triggerName($suffix)),
                $event,
                $table,
                $body,
            );
        }

        return $statements;
    }

    /**
     * @return list<string>
     */
    public function drop(TriggerDefinition $definition): array
    {
        return array_map(
            fn (string $suffix): string => 'DROP TRIGGER IF EXISTS '.$this->wrap($definition, $definition->triggerName($suffix)),
            ['ai', 'au', 'ad'],
        );
    }

    abstract public function exists(TriggerDefinition $definition): bool;

    /** A cast of the key expression to text. */
    abstract protected function cast(string $expression): string;

    /**
     * The two statements every trigger runs, a missing stream row makes the insert fail loudly
     * because trace_changes.stream_id is NOT NULL.
     *
     * @return list<string>
     */
    protected function log(TriggerDefinition $definition, string $key, string $operation): array
    {
        $streams = $this->table($definition, 'trace_streams');
        $type = $this->quote($definition, $definition->modelType);

        return [
            "UPDATE {$streams} SET version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE model_type = {$type}",
            sprintf(
                'INSERT INTO %s (stream_id, version, model_key, operation, created_at) VALUES ((SELECT id FROM %s WHERE model_type = %s), (SELECT version FROM %s WHERE model_type = %s), %s, %s, CURRENT_TIMESTAMP)',
                $this->table($definition, 'trace_changes'),
                $streams,
                $type,
                $streams,
                $type,
                $key,
                $operation,
            ),
        ];
    }

    /** A soft delete is the update that fills the deleted_at column, the listener calls that `deleted` too. */
    protected function updateOperation(TriggerDefinition $definition): string
    {
        if ($definition->softDeleteColumn === null) {
            return "'changed'";
        }

        $column = $this->wrap($definition, $definition->softDeleteColumn);

        return "CASE WHEN OLD.{$column} IS NULL AND NEW.{$column} IS NOT NULL THEN 'deleted' ELSE 'changed' END";
    }

    protected function table(TriggerDefinition $definition, ?string $name = null): string
    {
        return $definition->connection->getQueryGrammar()->wrapTable($name ?? $definition->table);
    }

    protected function wrap(TriggerDefinition $definition, string $identifier): string
    {
        return $definition->connection->getQueryGrammar()->wrap($identifier);
    }

    protected function quote(TriggerDefinition $definition, string $value): string
    {
        return $definition->connection->getPdo()->quote($value);
    }
}
