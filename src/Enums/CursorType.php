<?php

declare(strict_types=1);

namespace Vortech\Trace\Enums;

use Vortech\Trace\Enums\Concerns\ComparesCases;

enum CursorType: string
{
    use ComparesCases;

    case Snapshot = 'snapshot';
    case Changes = 'changes';
}
