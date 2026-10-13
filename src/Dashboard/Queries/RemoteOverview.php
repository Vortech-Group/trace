<?php

declare(strict_types=1);

namespace Vortech\Trace\Dashboard\Queries;

use Illuminate\Support\Facades\Schema;
use Throwable;
use Vortech\Trace\Dashboard\Data\RemoteModel;
use Vortech\Trace\Dashboard\Data\RunRow;
use Vortech\Trace\Data\SyncDefinition;
use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Health\HealthChecker;
use Vortech\Trace\Models\TraceLink;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Remote\MapperResolver;
use Vortech\Trace\Source\Cursor;
use Vortech\Trace\Support\TraceConfig;

/** What the remote side knows about every #[Sync] model, from the local trace tables only. */
final readonly class RemoteOverview
{
    public function __construct(
        private HealthChecker $health,
        private MapperResolver $resolver,
        private RunPresenter $presenter,
    ) {}

    /**
     * @return list<RemoteModel>
     */
    public function models(): array
    {
        $tables = Schema::connection(TraceConfig::connection());

        if (! $tables->hasTable('trace_sync_states') || ! $tables->hasTable('trace_sync_runs') || ! $tables->hasTable('trace_links')) {
            return [];
        }

        $models = [];

        foreach ($this->health->remoteModels() as $class) {
            try {
                $models[] = $this->describe($class, $this->resolver->resolve($class));
            } catch (Throwable $exception) {
                $models[] = $this->broken($class, $exception);
            }
        }

        return $models;
    }

    /**
     * @param  class-string  $class
     */
    private function describe(string $class, SyncDefinition $definition): RemoteModel
    {
        $type = $definition->morphClass();
        $state = TraceSyncState::query()->where(['source_connection' => $definition->connection, 'feed' => $definition->feed, 'model_type' => $type])->first();
        $status = $state instanceof TraceSyncState ? $state->status : SyncStatus::New;
        [$label, $tone] = $this->label($status);

        $runs = array_values(TraceSyncRun::query()
            ->where(['feed' => $definition->feed, 'model_type' => $type])
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (TraceSyncRun $run): RunRow => $this->presenter->present($run))
            ->all());

        $note = match (true) {
            ! $state instanceof TraceSyncState => 'This model has never been synced. Start the first sync to bootstrap it.',
            $status->in(SyncStatus::Failed, SyncStatus::ReconcileRequired) && filled($state->last_error) => $state->last_error,
            $state->inBootstrap() => 'The bootstrap resumes from the last committed page.',
            default => null,
        };

        return new RemoteModel(
            class: $class,
            type: $type,
            name: class_basename($class),
            feed: "{$definition->feed} · {$definition->connection}",
            tone: $tone,
            status: $label,
            needsAttention: $status->in(SyncStatus::Failed, SyncStatus::ReconcileRequired),
            synced: $status->is(SyncStatus::Ready),
            links: $state instanceof TraceSyncState && filled($state->source_instance_id)
                ? TraceLink::query()->where(['source_instance_id' => $state->source_instance_id, 'feed' => $definition->feed, 'model_type' => $type])->count()
                : 0,
            lastSuccess: $state?->last_success_at,
            cursor: $this->cursor($state),
            schema: $state instanceof TraceSyncState && filled($state->schema_hash) ? substr($state->schema_hash, 0, 14).'…' : '—',
            instance: $state instanceof TraceSyncState && filled($state->source_instance_id) ? substr($state->source_instance_id, 0, 8) : '—',
            note: $note,
            runs: $runs,
        );
    }

    /**
     * @param  class-string  $class
     */
    private function broken(string $class, Throwable $exception): RemoteModel
    {
        return new RemoteModel(
            class: $class,
            type: $class,
            name: class_basename($class),
            feed: 'declaration invalid',
            tone: 'bad',
            status: 'Misconfigured',
            needsAttention: true,
            synced: false,
            links: 0,
            lastSuccess: null,
            cursor: '—',
            schema: '—',
            instance: '—',
            note: $exception->getMessage(),
            runs: [],
        );
    }

    /**
     * @return array{string, string} label and tone
     */
    private function label(SyncStatus $status): array
    {
        return match ($status) {
            SyncStatus::Ready => ['In sync', ''],
            SyncStatus::New => ['Never synced', 'warn'],
            SyncStatus::Bootstrapping => ['Bootstrapping', 'warn'],
            SyncStatus::ReconcileRequired => ['Reconcile required', 'warn'],
            SyncStatus::Failed => ['Failed', 'bad'],
        };
    }

    private function cursor(?TraceSyncState $state): string
    {
        $bootstrap = $state?->inBootstrap() === true ? Cursor::peek($state->bootstrap_cursor) : null;

        if ($bootstrap !== null) {
            return filled($bootstrap['lastKey']) ? "snapshot after {$bootstrap['lastKey']}" : 'snapshot start';
        }

        $cursor = Cursor::peek($state?->cursor);

        return $cursor === null ? '—' : "changes @ {$cursor['version']}";
    }
}
