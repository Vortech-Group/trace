<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Throwable;
use Vortech\Trace\Exceptions\InstanceMismatchException;
use Vortech\Trace\Models\TraceSyncState;

/** Never mixes the links of two different source installations. */
final readonly class InstanceGuard
{
    /**
     * @throws InstanceMismatchException
     * @throws Throwable
     */
    public function check(TraceSyncState $state, string $instanceId): void
    {
        if (blank($state->source_instance_id)) {
            $state->forceFill(['source_instance_id' => $instanceId])->save();

            return;
        }

        throw_if(
            condition: $state->source_instance_id !== $instanceId,
            exception: new InstanceMismatchException(
                "The source answers as instance [{$instanceId}] but [{$state->feed}] was synced from [{$state->source_instance_id}]. Trace never re-pairs on its own: restore the original source or reset the sync state deliberately.",
            ),
        );
    }
}
