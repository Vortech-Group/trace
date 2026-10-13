<?php

declare(strict_types=1);

namespace Vortech\Trace\Data;

/** What a page needs to know about the run it belongs to. */
final readonly class SyncContext
{
    public function __construct(
        public SyncDefinition $definition,
        public string $instanceId,
        public string $runId,
        public Tally $tally,
        /** the value written to trace_links.last_seen_run_id, null when the run does not track presence */
        public ?string $seenMarker = null,
    ) {}
}
