<?php

declare(strict_types=1);

namespace Vortech\Trace\Enums;

use Vortech\Trace\Enums\Concerns\ComparesCases;

enum SyncMode: string
{
    use ComparesCases;

    case Bootstrap = 'bootstrap';
    case Incremental = 'incremental';
    case Reconcile = 'reconcile';

    /** Whether the mode walks the whole snapshot (and may soft delete what vanished). */
    public function isFullTraversal(): bool
    {
        return $this->in(self::Bootstrap, self::Reconcile);
    }
}
