<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ReflectionClass;
use Throwable;
use Vortech\Trace\Attributes\Sync;
use Vortech\Trace\Data\SyncDefinition;
use Vortech\Trace\Exceptions\InvalidMappingException;
use Vortech\Trace\Exceptions\InvalidSyncDefinitionException;
use Vortech\Trace\Mapper;
use Vortech\Trace\Support\TraceConfig;

/** Reads and validates the #[Sync] declaration of a model. */
final class MapperResolver
{
    /** @var array<string, SyncDefinition> */
    private array $cache = [];

    /**
     * @throws InvalidSyncDefinitionException
     * @throws InvalidMappingException
     * @throws Throwable
     */
    public function resolve(string $model): SyncDefinition
    {
        return $this->cache[$model] ??= $this->build($model);
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @throws InvalidSyncDefinitionException
     * @throws InvalidMappingException
     * @throws Throwable
     */
    private function build(string $model): SyncDefinition
    {
        throw_unless(
            condition: class_exists($model) && is_subclass_of($model, Model::class),
            exception: new InvalidSyncDefinitionException("[{$model}] is not an Eloquent model."),
        );

        $sync = $this->attribute($model);

        throw_if(
            condition: blank($sync),
            exception: new InvalidSyncDefinitionException("[{$model}] has no #[Sync] attribute."),
        );

        throw_if(
            condition: blank($sync->source),
            exception: new InvalidSyncDefinitionException("The #[Sync] source of [{$model}] must name a feed."),
        );

        throw_unless(
            condition: class_exists($sync->mapper) && is_subclass_of($sync->mapper, Mapper::class),
            exception: new InvalidSyncDefinitionException("The mapper [{$sync->mapper}] of [{$model}] must extend ".Mapper::class.'.'),
        );

        throw_if(
            condition: TraceConfig::requireSoftDeletes() && ! in_array(SoftDeletes::class, class_uses_recursive($model), true),
            exception: new InvalidSyncDefinitionException("[{$model}] must use SoftDeletes: Trace only ever soft deletes."),
        );

        $mapper = new ($sync->mapper);
        $this->validateMapper($mapper, new $model);

        return new SyncDefinition($model, $sync->source, $mapper, $sync->connection);
    }

    /**
     * @throws InvalidMappingException
     * @throws Throwable
     */
    private function validateMapper(Mapper $mapper, Model $model): void
    {
        $name = $mapper::class;

        throw_if(
            condition: blank($mapper->mapping()),
            exception: new InvalidMappingException("{$name}::fields() must not be empty."),
        );

        $locals = array_values($mapper->mapping());

        throw_if(
            condition: count(array_unique($locals)) !== count($locals),
            exception: new InvalidMappingException("{$name}::fields() maps two remote attributes to one local column."),
        );

        $protected = [$model->getKeyName()];

        if (method_exists($model, 'getDeletedAtColumn')) {
            $protected[] = $model->getDeletedAtColumn();
        }

        foreach ([...$locals, ...array_values($mapper->match())] as $column) {
            throw_if(
                condition: in_array($column, $protected, true),
                exception: new InvalidMappingException("{$name} must not write the column [{$column}]: it is managed by Eloquent or Trace."),
            );
        }
    }

    /**
     * @param  class-string  $model
     */
    private function attribute(string $model): ?Sync
    {
        for ($reflection = new ReflectionClass($model); $reflection !== false; $reflection = $reflection->getParentClass()) {
            $attributes = $reflection->getAttributes(Sync::class);

            if (filled($attributes)) {
                return $attributes[0]->newInstance();
            }
        }

        return null;
    }
}
