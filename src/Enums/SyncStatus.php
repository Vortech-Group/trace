<?php

declare(strict_types=1);

namespace Vortech\Trace\Enums;

use Vortech\Trace\Enums\Concerns\ComparesCases;

/** Lifecycle of a trace_sync_states row. */
enum SyncStatus: string
{
    use ComparesCases;

    case New = 'new';
    case Bootstrapping = 'bootstrapping';
    case Ready = 'ready';
    case Failed = 'failed';
    case ReconcileRequired = 'reconcile_required';

    public function needsBootstrap(): bool
    {
        return $this->in(self::New, self::Bootstrapping);
    }
}
