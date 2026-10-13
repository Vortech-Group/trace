<?php

declare(strict_types=1);

namespace Vortech\Trace\Capture;

final class MySqlDialect extends TriggerDialect
{
    public function exists(TriggerDefinition $definition): bool
    {
        return $definition->connection->selectOne(
            'select 1 as present from information_schema.triggers where trigger_schema = database() and trigger_name = ?',
            [$definition->triggerName('ai')],
        ) !== null;
    }

    protected function cast(string $expression): string
    {
        return "CAST({$expression} AS CHAR)";
    }
}
