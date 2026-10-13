<?php

declare(strict_types=1);

namespace Vortech\Trace\Enums;

use Vortech\Trace\Enums\Concerns\ComparesCases;

enum RunStatus: string
{
    use ComparesCases;

    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this->isNot(self::Running);
    }
}
