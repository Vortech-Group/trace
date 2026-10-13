<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Throwable;
use Vortech\Trace\Exceptions\DeletionGuardException;
use Vortech\Trace\Support\TraceConfig;

/** Refuses an unusually large "missing from the source" soft delete. */
final readonly class DeletionGuard
{
    /**
     * @throws DeletionGuardException
     * @throws Throwable
     */
    public function check(int $linked, int $missing, bool $override): void
    {
        if ($override || $missing === 0 || $linked < max(1, TraceConfig::minRecordsForRatioGuard())) {
            return;
        }

        $ratio = $missing / $linked;

        throw_if(
            condition: $ratio > TraceConfig::maxMissingDeletionRatio(),
            exception: new DeletionGuardException(sprintf(
                '%d of %d linked records (%.0f%%) vanished from the source, more than the allowed %.0f%%. Nothing was deleted; verify the source and run again with the mass deletion override.',
                $missing,
                $linked,
                $ratio * 100,
                TraceConfig::maxMissingDeletionRatio() * 100,
            )),
        );
    }
}
