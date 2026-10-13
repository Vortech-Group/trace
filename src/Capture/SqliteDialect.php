<?php

declare(strict_types=1);

namespace Vortech\Trace\Capture;

final class SqliteDialect extends TriggerDialect
{
    public function exists(TriggerDefinition $definition): bool
    {
        return $definition->connection->selectOne(
            "select 1 as present from sqlite_master where type = 'trigger' and name = ?",
            [$definition->triggerName('ai')],
        ) !== null;
    }

    protected function cast(string $expression): string
    {
        return "CAST({$expression} AS TEXT)";
    }
}
