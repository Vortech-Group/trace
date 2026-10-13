<?php

declare(strict_types=1);

namespace Vortech\Trace\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use Vortech\Trace\Attributes\Sync;
use Vortech\Trace\Attributes\Traceable;

/**
 * The name a model carries in the model_type columns of the trace tables. It is the alias of
 * #[Traceable(alias: ...)] or #[Sync(alias: ...)], and the model's morph class (the class name, or
 * its morph map alias) when none is declared. An alias keeps the log valid when a class is renamed or moved.
 */
final class ModelType
{
    /** @var array<class-string, string|null> */
    private static array $aliases = [];

    public static function of(Model $model): string
    {
        return self::alias($model::class) ?? $model->getMorphClass();
    }

    /**
     * The model class a stored name stands for, null when it is unknown to this application.
     *
     * @return class-string|null
     */
    public static function classFor(string $type): ?string
    {
        $morphed = Relation::getMorphedModel($type);

        if (is_string($morphed) && class_exists($morphed)) {
            return $morphed;
        }

        if (class_exists($type)) {
            return $type;
        }

        foreach (TraceConfig::remoteModels() as $class) {
            if (class_exists($class) && self::alias($class) === $type) {
                return $class;
            }
        }

        return null;
    }

    public static function flush(): void
    {
        self::$aliases = [];
    }

    /**
     * @param  class-string  $class
     */
    private static function alias(string $class): ?string
    {
        if (array_key_exists($class, self::$aliases)) {
            return self::$aliases[$class];
        }

        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            foreach ([Traceable::class, Sync::class] as $attribute) {
                foreach ($reflection->getAttributes($attribute) as $declared) {
                    $alias = $declared->newInstance()->alias;

                    if (filled($alias)) {
                        return self::$aliases[$class] = $alias;
                    }
                }
            }
        }

        return self::$aliases[$class] = null;
    }
}
