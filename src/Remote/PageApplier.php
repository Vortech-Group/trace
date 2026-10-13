<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Closure;
use Throwable;
use Vortech\Trace\Data\FeedPage;
use Vortech\Trace\Data\SyncContext;
use Vortech\Trace\Enums\Operation;
use Vortech\Trace\Models\TraceLink;

/** Applies a whole page in one local database transaction, together with whatever must commit with it. */
final readonly class PageApplier
{
    public function __construct(private RecordApplier $records) {}

    /**
     * @param  (Closure(): void)|null  $withPage  runs inside the same transaction, after the records (checkpoints)
     *
     * @throws Throwable
     */
    public function apply(SyncContext $context, FeedPage $page, ?Closure $withPage = null): void
    {
        (new TraceLink)->getConnection()->transaction(function () use ($context, $page, $withPage): void {
            foreach ($page->records as $record) {
                $this->records->apply($context, $record);
            }

            $this->markSeen($context, $page);

            if ($withPage instanceof Closure) {
                $withPage();
            }
        });
    }

    private function markSeen(SyncContext $context, FeedPage $page): void
    {
        if (blank($context->seenMarker)) {
            return;
        }

        $keys = [];

        foreach ($page->records as $record) {
            if ($record->operation->is(Operation::Upsert)) {
                $keys[] = $record->key;
            }
        }

        if (blank($keys)) {
            return;
        }

        TraceLink::query()
            ->where('source_instance_id', $context->instanceId)
            ->where('feed', $context->definition->feed)
            ->where('model_type', $context->definition->morphClass())
            ->whereIn('remote_key', $keys)
            ->update(['last_seen_run_id' => $context->seenMarker]);
    }
}
