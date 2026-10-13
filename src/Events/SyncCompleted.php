<?php

declare(strict_types=1);

namespace Vortech\Trace\Events;

use Vortech\Trace\Data\SyncResult;
use Vortech\Trace\Models\TraceSyncRun;

final readonly class SyncCompleted
{
    public function __construct(public TraceSyncRun $run, public SyncResult $result) {}
}
