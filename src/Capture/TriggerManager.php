<?php

declare(strict_types=1);

namespace Vortech\Trace\Capture;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use JsonException;
use Throwable;
use Vortech\Trace\Enums\CaptureMode;
use Vortech\Trace\Enums\TriggerStatus;
use Vortech\Trace\Exceptions\ConnectionMismatchException;
use Vortech\Trace\Exceptions\UnsupportedCaptureException;
use Vortech\Trace\Models\TraceMeta;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Source;
use Vortech\Trace\Source\SourceRegistry;
use Vortech\Trace\Tracking\TraceableRegistry;

/** Installs, removes and inspects the capture triggers of the models that use CaptureMode::Database. */
final readonly class TriggerManager
{
    public function __construct(
        private SourceRegistry $sources,
        private TraceableRegistry $traceables,
    ) {}

    /**
     * The registered feed models that are captured by the database.
     *
     * @return list<TriggerDefinition>
     *
     * @throws Throwable
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->sources->all() as $class) {
            $model = Source::resolve($class)->model();

            if ($this->traceables->captureFor($model)?->is(CaptureMode::Database) === true) {
                $definitions[$model] = $this->definitionFor($model);
            }
        }

        return array_values($definitions);
    }

    /**
     * @param  class-string  $model
     *
     * @throws ConnectionMismatchException
     * @throws UnsupportedCaptureException
     */
    public function definitionFor(string $model): TriggerDefinition
    {
        throw_unless(
            condition: is_subclass_of($model, Model::class),
            exception: new UnsupportedCaptureException("[{$model}] is not an Eloquent model."),
        );

        $instance = new $model;
        $connection = $instance->getConnection();
        $trace = new TraceStream()->getConnection();

        throw_if(
            condition: $connection->getName() !== $trace->getName(),
            exception: new ConnectionMismatchException(
                "{$model} writes on [{$connection->getName()}] but the trace tables live on [{$trace->getName()}]: a trigger can only write the change log of its own database.",
            ),
        );

        $this->dialect($connection);

        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($instance), true) && method_exists($instance, 'getDeletedAtColumn')
            ? $instance->getDeletedAtColumn()
            : null;

        return new TriggerDefinition(
            connection: $connection,
            modelClass: $model,
            modelType: $instance->getMorphClass(),
            table: $instance->getTable(),
            keyColumn: $instance->getKeyName(),
            softDeleteColumn: is_string($softDeletes) ? $softDeletes : null,
        );
    }

    /**
     * What install would run, in order.
     *
     * @return list<string>
     *
     * @throws UnsupportedCaptureException
     */
    public function statements(TriggerDefinition $definition): array
    {
        $dialect = $this->dialect($definition->connection);

        return [...$dialect->drop($definition), ...$dialect->create($definition)];
    }

    /**
     * Idempotent: an installed trigger is replaced, so it is also how an outdated one is refreshed.
     *
     * @throws JsonException
     * @throws UnsupportedCaptureException
     */
    public function install(TriggerDefinition $definition): void
    {
        // the stream row must exist before the first write: the trigger only updates it
        TraceStream::query()->firstOrCreate(['model_type' => $definition->modelType], ['version' => 0]);

        foreach ($this->statements($definition) as $statement) {
            $definition->connection->getPdo()->exec($statement);
        }

        TraceMeta::query()->updateOrCreate(['key' => $definition->metaKey()], ['value' => $definition->fingerprint()]);
    }

    /**
     * @throws UnsupportedCaptureException
     */
    public function remove(TriggerDefinition $definition): void
    {
        foreach ($this->dialect($definition->connection)->drop($definition) as $statement) {
            $definition->connection->getPdo()->exec($statement);
        }

        TraceMeta::query()->whereKey($definition->metaKey())->toBase()->delete();
    }

    /**
     * @throws JsonException
     * @throws UnsupportedCaptureException
     */
    public function status(TriggerDefinition $definition): TriggerStatus
    {
        if (! $this->dialect($definition->connection)->exists($definition)) {
            return TriggerStatus::Missing;
        }

        return TraceMeta::query()->whereKey($definition->metaKey())->value('value') === $definition->fingerprint()
            ? TriggerStatus::Installed
            : TriggerStatus::Outdated;
    }

    /** Whether trace_meta remembers a trigger of the model's table. */
    public function isRecorded(TriggerDefinition $definition): bool
    {
        return TraceMeta::query()->whereKey($definition->metaKey())->exists();
    }

    /**
     * @throws UnsupportedCaptureException
     */
    private function dialect(Connection $connection): TriggerDialect
    {
        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => new MySqlDialect,
            'pgsql' => new PostgresDialect,
            'sqlite' => new SqliteDialect,
            default => throw new UnsupportedCaptureException("Database capture supports MySQL, MariaDB, PostgreSQL and SQLite, not [{$connection->getDriverName()}]."),
        };
    }
}
