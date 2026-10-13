<?php

declare(strict_types=1);

namespace Vortech\Trace\Routing;

use Illuminate\Routing\Router;
use Throwable;
use Vortech\Trace\Exceptions\InvalidSourceException;
use Vortech\Trace\Http\Controllers\ChangesController;
use Vortech\Trace\Http\Controllers\SnapshotController;
use Vortech\Trace\Source;

/**
 * Route::source('clinics', ClinicSource::class). Controller class actions with the source
 * stored as route defaults, so the routes survive `route:cache`.
 */
final readonly class RegistersSourceRoutes
{
    public function __construct(private Router $router) {}

    /**
     * @throws InvalidSourceException
     * @throws Throwable
     */
    public function __invoke(string $feed, string $source): void
    {
        throw_unless(
            condition: preg_match('/^[A-Za-z0-9_.-]+$/', $feed) === 1,
            exception: new InvalidSourceException("The feed name [{$feed}] may only contain letters, digits, dots, dashes and underscores."),
        );

        throw_unless(
            condition: is_subclass_of($source, Source::class),
            exception: new InvalidSourceException("[{$source}] must extend ".Source::class.'.'),
        );

        foreach (['snapshot' => SnapshotController::class, 'changes' => ChangesController::class] as $endpoint => $controller) {
            $this->router->get("{$feed}/{$endpoint}", $controller)
                ->name("trace.{$feed}.{$endpoint}")
                ->middleware(['throttle:trace', 'trace.auth'])
                ->defaults('trace_feed', $feed)
                ->defaults('trace_source', $source);
        }
    }
}
