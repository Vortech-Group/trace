<?php

declare(strict_types=1);

namespace Vortech\Trace\Source;

use Illuminate\Routing\Router;
use Vortech\Trace\Source;

/**
 * The sources that were registered with Route::source(). They are read from the router's
 * route defaults, so the registry also works from a cached route file.
 */
final readonly class SourceRegistry
{
    public function __construct(private Router $router) {}

    /**
     * @return array<string, class-string<Source>> feed => source class
     */
    public function all(): array
    {
        $sources = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $feed = $route->defaults['trace_feed'] ?? null;
            $source = $route->defaults['trace_source'] ?? null;

            if (is_string($feed) && is_string($source) && is_subclass_of($source, Source::class)) {
                $sources[$feed] = $source;
            }
        }

        return $sources;
    }
}
