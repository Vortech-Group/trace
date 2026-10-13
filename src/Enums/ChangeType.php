<?php

declare(strict_types=1);

namespace Vortech\Trace\Enums;

use Vortech\Trace\Enums\Concerns\ComparesCases;

/** What the source change log remembers about a key. */
enum ChangeType: string
{
    use ComparesCases;

    case Changed = 'changed';
    case Deleted = 'deleted';

    public function operation(): Operation
    {
        return $this->is(self::Deleted) ? Operation::Delete : Operation::Upsert;
    }
}
