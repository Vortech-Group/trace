<?php

declare(strict_types=1);

namespace Vortech\Trace\Enums\Concerns;

trait ComparesCases
{
    public function is(self $case): bool
    {
        return $this === $case;
    }

    public function isNot(self $case): bool
    {
        return ! $this->is($case);
    }

    public function in(self ...$cases): bool
    {
        return in_array($this, $cases, true);
    }

    public function notIn(self ...$cases): bool
    {
        return ! $this->in(...$cases);
    }
}
