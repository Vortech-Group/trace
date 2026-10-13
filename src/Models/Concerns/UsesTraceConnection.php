<?php

declare(strict_types=1);

namespace Vortech\Trace\Models\Concerns;

use Vortech\Trace\Support\TraceConfig;

trait UsesTraceConnection
{
    public function getConnectionName(): ?string
    {
        return TraceConfig::connection() ?? parent::getConnectionName();
    }
}
