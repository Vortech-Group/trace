<?php

declare(strict_types=1);

namespace Vortech\Trace\Tracking;

use ReflectionClass;
use Vortech\Trace\Attributes\Traceable;
use Vortech\Trace\Enums\CaptureMode;
use Vortech\Trace\Source;

/** Answers "is this model class #[Traceable]" through Reflection, once per class. */
final class TraceableRegistry
{
    /** @var array<class-string, bool> */
    private array $cache = [];

    /** @var array<class-string, CaptureMode|null> */
    private array $captures = [];

    /**
     * @param  class-string  $class
     */
    public function isTraceable(string $class): bool
    {
        return $this->cache[$class] ??= $this->resolve($class);
    }

    /**
     * The Source declared by #[Traceable(source: ...)] on the model or its parents.
     *
     * @param  class-string  $class
     * @return class-string<Source>|null
     */
    public function sourceFor(string $class): ?string
    {
        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getAttributes(Traceable::class) as $attribute) {
                $source = $attribute->newInstance()->source;

                if (filled($source) && is_subclass_of($source, Source::class)) {
                    return $source;
                }
            }
        }

        return null;
    }

    /**
     * How the model is captured, null when it is not #[Traceable] at all.
     *
     * @param  class-string  $class
     */
    public function captureFor(string $class): ?CaptureMode
    {
        if (array_key_exists($class, $this->captures)) {
            return $this->captures[$class];
        }

        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getAttributes(Traceable::class) as $attribute) {
                return $this->captures[$class] = $attribute->newInstance()->capture;
            }
        }

        return $this->captures[$class] = null;
    }

    public function flush(): void
    {
        $this->cache = [];
        $this->captures = [];
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
