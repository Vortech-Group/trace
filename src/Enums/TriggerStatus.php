<?php

declare(strict_types=1);

namespace Vortech\Trace\Enums;

use Vortech\Trace\Enums\Concerns\ComparesCases;

enum TriggerStatus: string
{
    use ComparesCases;

    case Missing = 'missing';
    case Outdated = 'outdated';
    case Installed = 'installed';
}
