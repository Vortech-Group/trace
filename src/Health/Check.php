<?php

declare(strict_types=1);

namespace Vortech\Trace\Health;

use Vortech\Trace\Enums\CheckStatus;

/** One line of the health report: what was checked, how it went and why. */
final readonly class Check
{
    public function __construct(
        public string $label,
        public CheckStatus $status,
        public string $detail,
    ) {}

    public function failed(): bool
    {
        return $this->status->is(CheckStatus::Failed);
    }
}
