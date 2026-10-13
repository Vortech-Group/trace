<?php

declare(strict_types=1);

namespace Vortech\Trace\Events;

use Throwable;
use Vortech\Trace\Models\TraceSyncRun;

final readonly class SyncFailed
{
    public function __construct(public TraceSyncRun $run, public Throwable $exception) {}
}
