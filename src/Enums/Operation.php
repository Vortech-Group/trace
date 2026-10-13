<?php

declare(strict_types=1);

namespace Vortech\Trace\Enums;

use Vortech\Trace\Enums\Concerns\ComparesCases;

/** What the changes endpoint tells the remote to do with a key. */
enum Operation: string
{
    use ComparesCases;

    case Upsert = 'upsert';
    case Delete = 'delete';
}
