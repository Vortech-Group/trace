<?php

declare(strict_types=1);

namespace Vortech\Trace\Health;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use Vortech\Trace\Capture\TriggerManager;
use Vortech\Trace\Enums\CaptureMode;
use Vortech\Trace\Enums\CheckStatus;
use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Enums\TriggerStatus;
use Vortech\Trace\Exceptions\TraceException;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Remote\MapperResolver;
use Vortech\Trace\Remote\RemoteHttpClient;
use Vortech\Trace\Source;
use Vortech\Trace\Source\SourceRegistry;
use Vortech\Trace\Support\ModelType;
use Vortech\Trace\Support\TraceConfig;
use Vortech\Trace\Tracking\TraceableRegistry;

/** Validates the Trace setup. Shared by trace:doctor and the dashboard. */
final readonly class HealthChecker
{
    public function __construct(
        private SourceRegistry $sources,
        private MapperResolver $resolver,
        private RemoteHttpClient $client,
        private TriggerManager $triggers,
        private TraceableRegistry $traceables,
    ) {}

    /**
     * @return list<Check>
     */
    public function source(): array
    {
        $feeds = $this->sources->all();
        $hasSourceTables = $this->hasTable('trace_streams');

        if (blank($feeds) && ! $hasSourceTables) {
            return [new Check('Source side', CheckStatus::Skipped, 'not used')];
        }

        $checks = [
            $this->run('Source token is configured', function (): string {
                throw_if(
                    condition: blank(TraceConfig::sourceToken()),
                    exception: new RuntimeException('TRACE_SOURCE_TOKEN is not set: the feed answers 503.'),
                );

                return 'set';
            }),
            $this->run('Source tables exist', function () use ($hasSourceTables): string {
                throw_unless(
                    condition: $hasSourceTables && $this->hasTable('trace_changes') && $this->hasTable('trace_meta'),
                    exception: new RuntimeException('Run the source migration.'),
                );

                return 'trace_streams, trace_changes, trace_meta';
            }),
        ];

        foreach ($this->sources->all() as $feed => $class) {
            $checks[] = $this->run("Feed [{$feed}] is valid", fn (): string => $this->feedIsValid($class));
            $checks[] = $this->run("Feed [{$feed}] capture", fn (): string => $this->capture($class));
            $checks[] = $this->run("Feed [{$feed}] retention", function () use ($class): string {
                $stream = TraceStream::query()->where('model_type', Source::resolve($class)->morphClass())->first();

                return $stream instanceof TraceStream
                    ? "version {$stream->version}, pruned up to {$stream->pruned_version}, keeping ".TraceConfig::retentionDays().' days'
                    : 'no change recorded yet';
            });
        }

        return $checks;
    }

    /**
     * @param  bool  $reachSource  whether to ask the source for a page (a network request)
     * @return list<Check>
     */
    public function remote(bool $reachSource = true): array
    {
        $models = $this->remoteModels();

        if (blank($models)) {
            return [new Check('Remote side', CheckStatus::Skipped, 'not used')];
        }

        $checks = [
            $this->run('Remote tables exist', function (): string {
                throw_unless(
                    condition: $this->hasTable('trace_links') && $this->hasTable('trace_sync_states') && $this->hasTable('trace_sync_runs'),
                    exception: new RuntimeException('Run the remote migration.'),
                );

                return 'trace_links, trace_sync_states, trace_sync_runs';
            }),
            $this->run('Cache store supports atomic locks', function (): string {
                throw_unless(
                    condition: Cache::getStore() instanceof LockProvider,
                    exception: new RuntimeException('Use redis, database, file or memcached for the run lock.'),
                );

                return Cache::getDefaultDriver();
            }),
        ];

        foreach ($models as $class) {
            $checks[] = $this->run("[{$class}] declaration", fn (): string => $this->declaration($class));
            $checks[] = $this->run("[{$class}] state", fn (): string => $this->state($class));

            if ($reachSource) {
                $checks[] = $this->run("[{$class}] source reachable", fn (): string => $this->reachable($class));
            }
        }

        return $checks;
    }

    /**
     * @return list<class-string<Model>>
     */
    public function remoteModels(): array
    {
        $names = TraceConfig::remoteModels();

        if ($this->hasTable('trace_sync_states')) {
            foreach (TraceSyncState::query()->get() as $state) {
                $names[] = $state->model_type;
            }
        }

        $models = [];

        foreach (array_unique($names) as $name) {
            $class = ModelType::classFor($name) ?? $name;

            // a class listed in the config and its stored alias resolve to the same model
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $models[$class] = $class;
            }
        }

        return array_values($models);
    }

    /**
     * @param  class-string<Source>  $class
     *
     * @throws Throwable
     */
    private function feedIsValid(string $class): string
    {
        $source = Source::resolve($class);
        $source->validate();

        $missing = $source->missingColumns();

        throw_if(
            condition: filled($missing),
            exception: new RuntimeException('Fields that are no columns: '.implode(', ', $missing)),
        );

        $connection = $source->newModel()->getConnection()->getName();
        $traceConnection = (new TraceStream)->getConnection()->getName();

        throw_if(
            condition: $connection !== $traceConnection,
            exception: new RuntimeException("The model writes on [{$connection}], the trace tables live on [{$traceConnection}]."),
        );

        return count($source->fields()).' fields';
    }

    /**
     * Describes how the feed's model is captured, or throws why that is broken.
     *
     * @param  class-string<Source>  $class
     *
     * @throws Throwable
     */
    public function capture(string $class): string
    {
        $model = Source::resolve($class)->model();
        $mode = $this->traceables->captureFor($model);

        if ($mode?->is(CaptureMode::Database) === true) {
            $status = $this->triggers->status($this->triggers->definitionFor($model));

            throw_if(
                condition: $status->isNot(TriggerStatus::Installed),
                exception: new RuntimeException("Database triggers are {$status->value}: run php artisan trace:triggers install."),
            );

            return 'database triggers';
        }

        try {
            $definition = $this->triggers->definitionFor($model);
        } catch (TraceException) {
            return 'model events';
        }

        // a trigger left behind next to the listener only doubles the log
        throw_if(
            condition: $this->triggers->isRecorded($definition),
            exception: new RuntimeException('Triggers are still installed but the model is captured by events: run php artisan trace:triggers remove --model='.$model),
        );

        return 'model events';
    }

    /**
     * @param  class-string<Model>  $class
     *
     * @throws Throwable
     */
    private function declaration(string $class): string
    {
        $definition = $this->resolver->resolve($class);
        $model = $definition->newModel();
        $schema = Schema::connection($model->getConnectionName());

        $missing = array_values(array_filter(
            [...$definition->mapper->localColumns(), ...array_values($definition->mapper->match())],
            fn (string $column): bool => ! $schema->hasColumn($model->getTable(), $column),
        ));
        throw_if(
            condition: filled($missing),
            exception: new RuntimeException('Missing local columns: '.implode(', ', $missing)),
        );

        $traceConnection = (new TraceSyncState)->getConnection()->getName();
        throw_if(
            condition: $model->getConnection()->getName() !== $traceConnection,
            exception: new RuntimeException('The model is not on the trace connection, a page cannot commit atomically.'),
        );

        TraceConfig::remote($definition->connection);

        return "feed {$definition->feed}";
    }

    /**
     * @param  class-string<Model>  $class
     *
     * @throws Throwable
     */
    private function state(string $class): string
    {
        $definition = $this->resolver->resolve($class);
        $state = TraceSyncState::query()->where(['source_connection' => $definition->connection, 'feed' => $definition->feed, 'model_type' => $definition->morphClass()])->first();

        if (! $state instanceof TraceSyncState) {
            return 'never synced';
        }

        throw_if(
            condition: $state->status->in(SyncStatus::ReconcileRequired, SyncStatus::Failed),
            exception: new RuntimeException("{$state->status->value}: {$state->last_error}"),
        );

        return $state->status->value.(blank($state->last_success_at) ? '' : ", last success {$state->last_success_at->diffForHumans()}");
    }

    /**
     * @param  class-string<Model>  $class
     *
     * @throws Throwable
     */
    private function reachable(string $class): string
    {
        $definition = $this->resolver->resolve($class);
        $page = $this->client->snapshot($definition, null, 1);
        $state = TraceSyncState::query()->where(['source_connection' => $definition->connection, 'feed' => $definition->feed, 'model_type' => $definition->morphClass()])->first();

        if ($state instanceof TraceSyncState) {
            throw_if(
                condition: filled($state->source_instance_id) && $state->source_instance_id !== $page->instanceId,
                exception: new RuntimeException('The source instance id changed.'),
            );
            throw_if(
                condition: filled($state->schema_hash) && $state->schema_hash !== $page->schemaHash,
                exception: new RuntimeException('The published schema changed: reconcile is required.'),
            );
        }

        return "protocol 1, instance {$page->instanceId}";
    }

    /**
     * @param  Closure(): string  $callback
     */
    private function run(string $label, Closure $callback): Check
    {
        try {
            return new Check($label, CheckStatus::Passed, $callback());
        } catch (Throwable $exception) {
            return new Check($label, CheckStatus::Failed, $exception->getMessage());
        }
    }

    private function hasTable(string $table): bool
    {
        return Schema::connection(TraceConfig::connection())->hasTable($table);
    }
}
