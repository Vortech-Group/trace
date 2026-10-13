<?php

declare(strict_types=1);

namespace Vortech\Trace\Enums;

use Vortech\Trace\Enums\Concerns\ComparesCases;

enum CheckStatus: string
{
    use ComparesCases;

    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
