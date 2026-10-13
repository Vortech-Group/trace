<?php

declare(strict_types=1);

namespace Vortech\Trace\Capture;

/** PostgreSQL takes one trigger for all events, which calls one PL/pgSQL function. */
final class PostgresDialect extends TriggerDialect
{
    public function create(TriggerDefinition $definition): array
    {
        $key = $this->wrap($definition, $definition->keyColumn);
        $streams = $this->table($definition, 'trace_streams');
        $changes = $this->table($definition, 'trace_changes');
        $type = $this->quote($definition, $definition->modelType);
        $function = $this->wrap($definition, $definition->triggerName('fn'));

        $update = $definition->softDeleteColumn === null
            ? ''
            : $this->softDeleteBranch($definition);

        $body = <<<SQL
        DECLARE
            v_stream_id bigint;
            v_version bigint;
            change_key text;
            change_operation text := 'changed';
        BEGIN
            IF TG_OP = 'DELETE' THEN
                change_key := CAST(OLD.{$key} AS TEXT);
                change_operation := 'deleted';
            ELSE
                change_key := CAST(NEW.{$key} AS TEXT);{$update}
            END IF;

            UPDATE {$streams} SET version = version + 1, updated_at = CURRENT_TIMESTAMP
                WHERE model_type = {$type} RETURNING id, version INTO STRICT v_stream_id, v_version;

            INSERT INTO {$changes} (stream_id, version, model_key, operation, created_at)
                VALUES (v_stream_id, v_version, change_key, change_operation, CURRENT_TIMESTAMP);

            RETURN NULL;
        END;
        SQL;

        return [
            "CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$trace\$\n{$body}\n\$trace\$",
            sprintf(
                'CREATE TRIGGER %s AFTER INSERT OR UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION %s()',
                $this->wrap($definition, $definition->triggerName('row')),
                $this->table($definition),
                $function,
            ),
        ];
    }

    public function drop(TriggerDefinition $definition): array
    {
        return [
            sprintf('DROP TRIGGER IF EXISTS %s ON %s', $this->wrap($definition, $definition->triggerName('row')), $this->table($definition)),
            sprintf('DROP FUNCTION IF EXISTS %s()', $this->wrap($definition, $definition->triggerName('fn'))),
        ];
    }

    public function exists(TriggerDefinition $definition): bool
    {
        return $definition->connection->selectOne(
            'select 1 as present from pg_trigger t join pg_class c on c.oid = t.tgrelid where t.tgname = ? and c.relname = ? and not t.tgisinternal',
            [$definition->triggerName('row'), $definition->physicalTable()],
        ) !== null;
    }

    protected function cast(string $expression): string
    {
        return "CAST({$expression} AS TEXT)";
    }

    private function softDeleteBranch(TriggerDefinition $definition): string
    {
        $column = $this->wrap($definition, (string) $definition->softDeleteColumn);

        return "\n                IF TG_OP = 'UPDATE' THEN\n                    IF OLD.{$column} IS NULL AND NEW.{$column} IS NOT NULL THEN\n                        change_operation := 'deleted';\n                    END IF;\n                END IF;";
    }
}
