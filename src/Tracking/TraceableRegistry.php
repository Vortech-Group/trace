<?php

declare(strict_types=1);

namespace Vortech\Trace\Tracking;

use ReflectionClass;
use Vortech\Trace\Attributes\Traceable;

/** Answers "is this model class #[Traceable]" through Reflection, once per class. */
final class TraceableRegistry
{
    /** @var array<class-string, bool> */
    private array $cache = [];

    /**
     * @param  class-string  $class
     */
    public function isTraceable(string $class): bool
    {
        return $this->cache[$class] ??= $this->resolve($class);
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @param  class-string  $class
     */
    private function resolve(string $class): bool
    {
        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            if (filled($reflection->getAttributes(Traceable::class))) {
                return true;
            }
        }

        return false;
    }
}
