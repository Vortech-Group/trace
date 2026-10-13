<?php

declare(strict_types=1);

namespace Vortech\Trace\Enums;

use Vortech\Trace\Enums\Concerns\ComparesCases;

/** How the changes of a #[Traceable] model reach the change log. */
enum CaptureMode: string
{
    use ComparesCases;

    /** The Eloquent listener writes the log: any write that fires no model event is missed. */
    case Events = 'events';

    /** Database triggers write the log: every write is seen, installed with trace:triggers. */
    case Database = 'database';
}
