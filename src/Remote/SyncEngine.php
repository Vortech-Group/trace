<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vortech\Trace\Data\SyncContext;
use Vortech\Trace\Data\SyncDefinition;
use Vortech\Trace\Data\SyncRequest;
use Vortech\Trace\Data\SyncResult;
use Vortech\Trace\Data\Tally;
use Vortech\Trace\Enums\RunStatus;
use Vortech\Trace\Enums\SyncMode;
use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Events\SyncCompleted;
use Vortech\Trace\Events\SyncFailed;
use Vortech\Trace\Events\SyncStarted;
use Vortech\Trace\Exceptions\CursorExpiredException;
use Vortech\Trace\Exceptions\DeletionGuardException;
use Vortech\Trace\Exceptions\ReconcileRequiredException;
use Vortech\Trace\Exceptions\SchemaMismatchException;
use Vortech\Trace\Exceptions\TraceException;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Models\TraceSyncState;

/** Decides the mode of a run, holds the lock, keeps the state machine honest. */
final readonly class SyncEngine
{
    public function __construct(
        private MapperResolver $resolver,
        private SyncLock $locks,
        private SnapshotRunner $snapshots,
        private IncrementalRunner $incremental,
    ) {}

    /**
     * @throws Throwable
     */
    public function run(SyncRequest $request): SyncResult
    {
        $definition = $this->resolver->resolve($request->model);
        $lock = $this->locks->acquire($definition);

        try {
            return $request->dryRun ? $this->rehearse($definition, $request) : $this->execute($definition, $request);
        } finally {
            $lock->release();
        }
    }

    /**
     * The real run, written into a transaction that is always rolled back: the source is read, the pages are applied
     * through the same code (so ambiguous matches and constraint violations surface), nothing stays. Model events
     * and the sync events are silenced, so no observer or listener reacts to rows that will not exist.
     *
     * @throws Throwable
     */
    private function rehearse(SyncDefinition $definition, SyncRequest $request): SyncResult
    {
        $connection = new TraceSyncState()->getConnection();
        $connection->beginTransaction();

        try {
            return Model::withoutEvents(fn (): SyncResult => $this->execute($definition, $request));
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @throws Throwable
     */
    private function execute(SyncDefinition $definition, SyncRequest $request): SyncResult
    {
        $state = TraceSyncState::query()->firstOrCreate(
            ['source_connection' => $definition->connection, 'feed' => $definition->feed, 'model_type' => $definition->morphClass()],
            ['status' => SyncStatus::New],
        );

        $mode = $this->mode($state, $request);
        $started = hrtime(true);

        $run = TraceSyncRun::query()->create([
            'source_instance_id' => $state->source_instance_id,
            'feed' => $definition->feed,
            'model_type' => $definition->morphClass(),
            'mode' => $mode,
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);

        if (! $request->dryRun) {
            event(new SyncStarted($run));
        }

        $tally = new Tally;

        try {
            $cursor = $mode->isFullTraversal()
                ? $this->snapshots->run($definition, $state, $run, $mode, $request->allowMassDeletion, $tally)
                : $this->incrementally($definition, $state, $run, $tally);

            $state->forceFill(['status' => SyncStatus::Ready, 'last_success_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $exception) {
            $this->fail($state, $run, $tally, $exception, $request->dryRun);

            throw $exception;
        }

        $result = new SyncResult(
            runId: $run->id,
            mode: $mode,
            created: $tally->created,
            updated: $tally->updated,
            unchanged: $tally->unchanged,
            deleted: $tally->deleted,
            restored: $tally->restored,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
            cursor: $cursor,
            matched: $tally->matched,
            dryRun: $request->dryRun,
        );

        $run->forceFill([
            'source_instance_id' => $state->source_instance_id,
            'status' => RunStatus::Completed,
            'finished_at' => now(),
            ...$this->counters($tally),
        ])->save();

        if (! $request->dryRun) {
            Log::info('trace.sync.completed', ['feed' => $definition->feed, 'model' => $definition->model, ...$result->toArray()]);
            event(new SyncCompleted($run, $result));
        }

        return $result;
    }

    /**
     * @throws ReconcileRequiredException
     * @throws Throwable
     */
    private function mode(TraceSyncState $state, SyncRequest $request): SyncMode
    {
        throw_if(
            condition: $state->status->is(SyncStatus::ReconcileRequired) && ! $request->reconcile,
            exception: new ReconcileRequiredException("[{$state->feed}] needs a full reconcile before it can sync again: {$state->last_error}"),
        );

        if (blank($state->cursor)) {
            return SyncMode::Bootstrap;
        }

        return $request->reconcile ? SyncMode::Reconcile : SyncMode::Incremental;
    }

    /**
     * @throws Throwable
     */
    private function incrementally(SyncDefinition $definition, TraceSyncState $state, TraceSyncRun $run, Tally $tally): string
    {
        $instance = (string) $state->source_instance_id;
        $context = new SyncContext($definition, $instance, $run->id, $tally);

        return $this->incremental->run($context, $state, $state->cursor, null, $state->schema_hash, persist: true);
    }

    private function fail(TraceSyncState $state, TraceSyncRun $run, Tally $tally, Throwable $exception, bool $dryRun): void
    {
        $code = $exception instanceof TraceException ? $exception->errorCode : 'unexpected_error';

        // a cursor that fell out of retention, a reshaped feed and a refused mass delete are never retried blindly
        $reconcile = $exception instanceof CursorExpiredException
            || $exception instanceof DeletionGuardException
            || ($exception instanceof SchemaMismatchException && $exception->errorCode === 'schema_changed');

        $message = mb_substr($exception->getMessage(), 0, 1000);

        $state->forceFill([
            'status' => $reconcile ? SyncStatus::ReconcileRequired : SyncStatus::Failed,
            'last_error' => $message,
        ])->save();

        $run->forceFill([
            'status' => RunStatus::Failed,
            'finished_at' => now(),
            'errors' => 1,
            'last_error' => $message,
            ...$this->counters($tally),
        ])->save();

        if ($dryRun) {
            return;
        }

        Log::warning('trace.sync.failed', ['feed' => $state->feed, 'model' => $state->model_type, 'code' => $code, 'run' => $run->id]);
        event(new SyncFailed($run, $exception));
    }

    /**
     * @return array<string, int>
     */
    private function counters(Tally $tally): array
    {
        return [
            'created' => $tally->created,
            'updated' => $tally->updated,
            'unchanged' => $tally->unchanged,
            'deleted' => $tally->deleted,
            'restored' => $tally->restored,
        ];
    }
}
