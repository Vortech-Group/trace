<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Throwable;
use Vortech\Trace\Data\SyncContext;
use Vortech\Trace\Data\SyncDefinition;
use Vortech\Trace\Data\Tally;
use Vortech\Trace\Enums\SyncMode;
use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Exceptions\CursorExpiredException;
use Vortech\Trace\Exceptions\ProtocolException;
use Vortech\Trace\Models\TraceLink;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Support\TraceConfig;

/**
 * The full traversal behind both the first bootstrap (resumable) and a reconcile (always
 * fresh): snapshot pages, the changes since the watermark, and only then the soft delete of
 * linked records that vanished. Any failure on the way skips the deletion.
 */
final readonly class SnapshotRunner
{
    public function __construct(
        private RemoteHttpClient $client,
        private PageApplier $pages,
        private IncrementalRunner $incremental,
        private InstanceGuard $instances,
        private DeletionGuard $guard,
    ) {}

    /**
     * @return string the final changes cursor
     *
     * @throws Throwable
     */
    public function run(SyncDefinition $definition, TraceSyncState $state, TraceSyncRun $run, SyncMode $mode, bool $allowMassDeletion, Tally $tally): string
    {
        $persist = $mode->is(SyncMode::Bootstrap);
        $resume = $persist && $state->inBootstrap() && filled($state->bootstrap_run_id);

        $cursor = $resume ? $state->bootstrap_cursor : null;
        $marker = $resume ? $state->bootstrap_run_id : $run->id;
        $watermark = $resume ? $state->bootstrap_watermark : null;
        $schemaHash = $resume ? $state->schema_hash : null;
        $context = null;

        while (true) {
            try {
                $page = $this->client->snapshot($definition, $cursor, TraceConfig::pageSize(), $state->source_instance_id);
            } catch (CursorExpiredException $exception) {
                throw_unless(
                    condition: $resume,
                    exception: $exception,
                );

                [$cursor, $marker, $watermark, $schemaHash, $resume] = [null, $run->id, null, null, false];

                continue;
            }

            $this->instances->check($state, $page->instanceId);

            if ($resume && filled($schemaHash) && $schemaHash !== $page->schemaHash) {
                // the feed was reshaped while the bootstrap was paused: start over, nothing of it is reusable
                [$cursor, $marker, $watermark, $schemaHash, $resume] = [null, $run->id, null, null, false];

                continue;
            }

            $watermark ??= $page->watermark;

            throw_if(
                condition: blank($watermark),
                exception: new ProtocolException('The snapshot response has no watermark.', 'invalid_payload'),
            );

            $schemaHash = $page->schemaHash;
            $context = new SyncContext($definition, $page->instanceId, $run->id, $tally, $marker);
            $fetchedWith = $cursor;

            $this->pages->apply($context, $page, $persist
                ? function () use ($state, $schemaHash, $page, $fetchedWith, $watermark, $marker): void {
                    $state->forceFill([
                        'status' => SyncStatus::Bootstrapping,
                        'schema_hash' => $schemaHash,
                        // the last page keeps the cursor it was fetched with, so a resume replays it and carries on
                        'bootstrap_cursor' => $page->nextCursor ?? $fetchedWith,
                        'bootstrap_watermark' => $watermark,
                        'bootstrap_run_id' => $marker,
                    ])->save();
                }
                : null);

            if (! $page->hasMore) {
                break;
            }

            $cursor = $page->nextCursor;
        }

        $final = $this->incremental->run($context, $state, null, $watermark, $schemaHash, persist: false);

        $this->deleteMissing($context, $marker, $allowMassDeletion);

        $state->forceFill([
            'cursor' => $final,
            'schema_hash' => $schemaHash,
            'bootstrap_cursor' => null,
            'bootstrap_watermark' => null,
            'bootstrap_run_id' => null,
        ])->save();

        return $final;
    }

    /**
     * @throws Throwable
     */
    private function deleteMissing(SyncContext $context, string $marker, bool $override): void
    {
        $definition = $context->definition;
        $links = fn () => TraceLink::query()
            ->where('source_instance_id', $context->instanceId)
            ->where('feed', $definition->feed)
            ->where('model_type', $definition->morphClass());

        $missing = [];
        $keyName = $definition->newModel()->getKeyName();

        $links()
            ->where(fn ($query) => $query->whereNull('last_seen_run_id')->orWhere('last_seen_run_id', '!=', $marker))
            ->chunkById(500, function ($chunk) use ($definition, $keyName, &$missing): void {
                $active = $definition->newModel()->newQuery()
                    ->whereIn($keyName, $chunk->pluck('model_key')->all())
                    ->pluck($keyName);

                foreach ($active as $key) {
                    $missing[] = is_scalar($key) ? (string) $key : '';
                }
            });

        $this->guard->check($links()->count(), count($missing), $override);

        foreach (array_chunk($missing, 100) as $keys) {
            new TraceLink()->getConnection()->transaction(function () use ($definition, $keyName, $keys, $context): void {
                foreach ($definition->newModel()->newQuery()->whereIn($keyName, $keys)->get() as $model) {
                    $model->delete();
                    $context->tally->deleted++;
                }
            });
        }
    }
}
