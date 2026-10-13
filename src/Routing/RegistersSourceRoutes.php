<?php

declare(strict_types=1);

namespace Vortech\Trace\Routing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Throwable;
use Vortech\Trace\Exceptions\InvalidSourceException;
use Vortech\Trace\Http\Controllers\ChangesController;
use Vortech\Trace\Http\Controllers\SnapshotController;
use Vortech\Trace\Source;
use Vortech\Trace\Tracking\TraceableRegistry;

/**
 * Route::source('clinics', ClinicSource::class) or Route::source('clinics', Clinic::class).
 * Controller class actions with the source stored as route defaults, so the routes survive `route:cache`.
 */
final readonly class RegistersSourceRoutes
{
    public function __construct(
        private Router $router,
        private TraceableRegistry $registry,
    ) {}

    /**
     * @param  class-string<Source>|class-string<Model>  $source  a Source, or a model whose #[Traceable] names one
     *
     * @throws InvalidSourceException
     * @throws Throwable
     */
    public function __invoke(string $feed, string $source): void
    {
        throw_unless(
            condition: preg_match('/^[A-Za-z0-9_.-]+$/', $feed) === 1,
            exception: new InvalidSourceException("The feed name [{$feed}] may only contain letters, digits, dots, dashes and underscores."),
        );

        $source = $this->resolve($source);

        foreach (['snapshot' => SnapshotController::class, 'changes' => ChangesController::class] as $endpoint => $controller) {
            $this->router->get("{$feed}/{$endpoint}", $controller)
                ->name("trace.{$feed}.{$endpoint}")
                ->middleware(['throttle:trace', 'trace.auth'])
                ->defaults('trace_feed', $feed)
                ->defaults('trace_source', $source);
        }
    }

    /**
     * @return class-string<Source>
     *
     * @throws InvalidSourceException
     * @throws Throwable
     */
    private function resolve(string $class): string
    {
        if (is_subclass_of($class, Source::class)) {
            return $class;
        }

        throw_unless(
            condition: is_subclass_of($class, Model::class),
            exception: new InvalidSourceException("[{$class}] must be a ".Source::class.' or an Eloquent model.'),
        );

        $source = $this->registry->sourceFor($class);

        throw_if(
            condition: blank($source),
            exception: new InvalidSourceException("[{$class}] does not name its source: use #[Traceable(source: YourSource::class)] or pass the Source class to Route::source()."),
        );

        throw_unless(
            condition: is_a($class, Source::resolve($source)->model(), true),
            exception: new InvalidSourceException("The source [{$source}] named by [{$class}] publishes a different model."),
        );

        return $source;
    }
}
