<?php

declare(strict_types=1);

namespace Vortech\Trace\Console;

use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use Vortech\Trace\Capture\TriggerManager;
use Vortech\Trace\Enums\CaptureMode;
use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Enums\TriggerStatus;
use Vortech\Trace\Exceptions\TraceException;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Remote\MapperResolver;
use Vortech\Trace\Remote\RemoteHttpClient;
use Vortech\Trace\Source;
use Vortech\Trace\Source\SourceRegistry;
use Vortech\Trace\Support\TraceConfig;
use Vortech\Trace\Tracking\TraceableRegistry;

#[Signature('trace:doctor {--offline : Skip the request to the source}')]
#[Description('Validate the Trace setup of the source and the remote side')]
final class DoctorCommand extends Command
{
    private int $failures = 0;

    public function handle(SourceRegistry $sources, MapperResolver $resolver, RemoteHttpClient $client, TriggerManager $triggers, TraceableRegistry $traceables): int
    {
        $this->components->twoColumnDetail('<fg=gray>Check</>', '<fg=gray>Result</>');

        $this->checkSource($sources, $triggers, $traceables);
        $this->checkRemote($resolver, $client);

        if ($this->failures > 0) {
            $this->components->error("{$this->failures} check(s) failed.");

            return self::FAILURE;
        }

        $this->components->info('Everything looks fine.');

        return self::SUCCESS;
    }

    private function checkSource(SourceRegistry $registry, TriggerManager $triggers, TraceableRegistry $traceables): void
    {
        $feeds = $registry->all();
        $hasSourceTables = $this->hasTable('trace_streams');

        if (blank($feeds) && ! $hasSourceTables) {
            $this->components->twoColumnDetail('Source side', '<fg=gray>not used</>');

            return;
        }

        $this->check('Source token is configured', function (): string {
            throw_if(
                condition: blank(TraceConfig::sourceToken()),
                exception: new RuntimeException('TRACE_SOURCE_TOKEN is not set: the feed answers 503.'),
            );

            return 'set';
        });

        $this->check('Source tables exist', function () use ($hasSourceTables): string {
            throw_unless(
                condition: $hasSourceTables && $this->hasTable('trace_changes') && $this->hasTable('trace_meta'),
                exception: new RuntimeException('Run the source migration.'),
            );

            return 'trace_streams, trace_changes, trace_meta';
        });

        foreach ($feeds as $feed => $class) {
            $this->check("Feed [{$feed}] is valid", function () use ($class): string {
                $source = Source::resolve($class);
                $source->validate();

                $missing = $source->missingColumns();

                throw_if(
                    condition: filled($missing),
                    exception: new RuntimeException('Fields that are no columns: '.implode(', ', $missing)),
                );

                $connection = $source->newModel()->getConnection()->getName();
                $traceConnection = new TraceStream()->getConnection()->getName();

                throw_if(
                    condition: $connection !== $traceConnection,
                    exception: new RuntimeException("The model writes on [{$connection}], the trace tables live on [{$traceConnection}]."),
                );

                return count($source->fields()).' fields';
            });

            $this->check("Feed [{$feed}] capture", function () use ($class, $triggers, $traceables): string {
                $model = Source::resolve($class)->model();
                $mode = $traceables->captureFor($model);

                if ($mode?->is(CaptureMode::Database) === true) {
                    $definition = $triggers->definitionFor($model);
                    $status = $triggers->status($definition);

                    throw_if(
                        condition: $status->isNot(TriggerStatus::Installed),
                        exception: new RuntimeException("Database triggers are {$status->value}: run php artisan trace:triggers install."),
                    );

                    return 'database triggers';
                }

                try {
                    $definition = $triggers->definitionFor($model);
                } catch (TraceException) {
                    return 'model events';
                }

                // a trigger left behind next to the listener only doubles the log
                throw_if(
                    condition: $triggers->isRecorded($definition),
                    exception: new RuntimeException('Triggers are still installed but the model is captured by events: run php artisan trace:triggers remove --model='.$model),
                );

                return 'model events';
            });

            $this->check("Feed [{$feed}] retention", function () use ($class): string {
                $stream = TraceStream::query()->where('model_type', Source::resolve($class)->morphClass())->first();

                return $stream instanceof TraceStream
                    ? "version {$stream->version}, pruned up to {$stream->pruned_version}, keeping ".TraceConfig::retentionDays().' days'
                    : 'no change recorded yet';
            });
        }
    }

    private function checkRemote(MapperResolver $resolver, RemoteHttpClient $client): void
    {
        $models = $this->remoteModels();

        if (blank($models)) {
            $this->components->twoColumnDetail('Remote side', '<fg=gray>not used</>');

            return;
        }

        $this->check('Remote tables exist', function (): string {
            throw_unless(
                condition: $this->hasTable('trace_links') && $this->hasTable('trace_sync_states') && $this->hasTable('trace_sync_runs'),
                exception: new RuntimeException('Run the remote migration.'),
            );

            return 'trace_links, trace_sync_states, trace_sync_runs';
        });

        $this->check('Cache store supports atomic locks', function (): string {
            throw_unless(
                condition: Cache::getStore() instanceof LockProvider,
                exception: new RuntimeException('Use redis, database, file or memcached for the run lock.'),
            );

            return Cache::getDefaultDriver();
        });

        foreach ($models as $class) {
            $this->check("[{$class}] declaration", function () use ($resolver, $class): string {
                $definition = $resolver->resolve($class);
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

                $traceConnection = new TraceSyncState()->getConnection()->getName();
                throw_if(
                    condition: $model->getConnection()->getName() !== $traceConnection,
                    exception: new RuntimeException('The model is not on the trace connection, a page cannot commit atomically.'),
                );

                TraceConfig::remote($definition->connection);

                return "feed {$definition->feed}";
            });

            $this->check("[{$class}] state", function () use ($resolver, $class): string {
                $definition = $resolver->resolve($class);
                $state = TraceSyncState::query()->where(['source_connection' => $definition->connection, 'feed' => $definition->feed, 'model_type' => $definition->morphClass()])->first();

                if (! $state instanceof TraceSyncState) {
                    return 'never synced';
                }

                throw_if(
                    condition: $state->status->in(SyncStatus::ReconcileRequired, SyncStatus::Failed),
                    exception: new RuntimeException("{$state->status->value}: {$state->last_error}"),
                );

                return $state->status->value.(blank($state->last_success_at) ? '' : ", last success {$state->last_success_at->diffForHumans()}");
            });

            if ($this->option('offline') !== true) {
                $this->check("[{$class}] source reachable", function () use ($resolver, $client, $class): string {
                    $definition = $resolver->resolve($class);
                    $page = $client->snapshot($definition, null, 1);
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
                });
            }
        }
    }

    /**
     * @return list<class-string<Model>>
     */
    private function remoteModels(): array
    {
        $names = TraceConfig::remoteModels();

        if ($this->hasTable('trace_sync_states')) {
            foreach (TraceSyncState::query()->get() as $state) {
                $names[] = $state->model_type;
            }
        }

        $models = [];

        foreach (array_unique($names) as $name) {
            $class = Relation::getMorphedModel($name) ?? $name;

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $models[] = $class;
            }
        }

        return $models;
    }

    /**
     * @param  Closure(): string  $callback
     */
    private function check(string $label, Closure $callback): void
    {
        try {
            $this->components->twoColumnDetail($label, '<fg=green>'.$callback().'</>');
        } catch (Throwable $exception) {
            $this->failures++;
            $this->components->twoColumnDetail($label, '<fg=red>'.$exception->getMessage().'</>');
        }
    }

    private function hasTable(string $table): bool
    {
        return Schema::connection(TraceConfig::connection())->hasTable($table);
    }
}
