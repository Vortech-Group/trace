<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Throwable;
use Vortech\Trace\Data\SyncContext;
use Vortech\Trace\Exceptions\InstanceMismatchException;
use Vortech\Trace\Exceptions\ProtocolException;
use Vortech\Trace\Exceptions\SchemaMismatchException;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Support\TraceConfig;

/**
 * Walks the change feed page by page. Every page commits its records and (when persisting)
 * the cursor together, so a failure leaves the cursor in front of the failed page.
 */
final readonly class IncrementalRunner
{
    public function __construct(
        private RemoteHttpClient $client,
        private PageApplier $pages,
    ) {}

    /**
     * @param  bool  $persist  false while a snapshot traversal catches up: its state is finalised at the very end
     * @return string the final cursor
     *
     * @throws InstanceMismatchException
     * @throws SchemaMismatchException
     * @throws ProtocolException
     * @throws Throwable
     */
    public function run(
        SyncContext $context,
        TraceSyncState $state,
        ?string $cursor,
        ?string $since,
        ?string $expectedSchemaHash,
        bool $persist,
    ): string {
        $ceiling = null;
        $position = $cursor;

        while (true) {
            $page = $this->client->changes($context->definition, $position, $since, TraceConfig::pageSize(), $context->instanceId);

            throw_if(
                condition: $page->instanceId !== $context->instanceId,
                exception: new InstanceMismatchException('The source instance changed in the middle of the run; nothing was re-linked.'),
            );

            throw_if(
                condition: filled($expectedSchemaHash) && $page->schemaHash !== $expectedSchemaHash,
                exception: new SchemaMismatchException('The published schema of the feed changed: a reconcile is required.', 'schema_changed'),
            );

            $next = $page->nextCursor;

            throw_if(
                condition: blank($next),
                exception: new ProtocolException('The changes response has no next_cursor.', 'invalid_payload'),
            );

            $this->pages->apply($context, $page, $persist
                ? function () use ($state, $next): void {
                    $state->forceFill(['cursor' => $next])->save();
                }
                : null);

            $ceiling ??= filled($page->watermark) && ctype_digit($page->watermark) ? (int) $page->watermark : null;
            $last = array_last($page->records)?->version;

            throw_if(
                condition: $page->hasMore && $next === $position,
                exception: new ProtocolException('The source returned the same cursor twice; stopping to avoid an endless loop.', 'invalid_payload'),
            );

            $position = $next;
            $since = null;

            if (! $page->hasMore || (filled($ceiling) && filled($last) && ctype_digit($last) && (int) $last >= $ceiling)) {
                return $position;
            }
        }
    }
}
