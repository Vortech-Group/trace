<?php

declare(strict_types=1);

namespace Vortech\Trace\Events;

use Vortech\Trace\Models\TraceSyncRun;

final readonly class SyncStarted
{
    public function __construct(public TraceSyncRun $run) {}
}
