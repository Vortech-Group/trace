<?php

declare(strict_types=1);

namespace Vortech\Trace;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Vortech\Trace\Console\DoctorCommand;
use Vortech\Trace\Console\InstallCommand;
use Vortech\Trace\Console\MakeMapperCommand;
use Vortech\Trace\Console\MakeSourceCommand;
use Vortech\Trace\Console\PruneCommand;
use Vortech\Trace\Console\SyncCommand;
use Vortech\Trace\Console\TriggersCommand;
use Vortech\Trace\Dashboard\Http\Middleware\AuthorizeDashboard;
use Vortech\Trace\Http\Middleware\AuthenticateTraceSource;
use Vortech\Trace\Remote\MapperResolver;
use Vortech\Trace\Remote\RemoteManager;
use Vortech\Trace\Routing\RegistersSourceRoutes;
use Vortech\Trace\Support\TraceConfig;
use Vortech\Trace\Tracking\TraceableEventListener;
use Vortech\Trace\Tracking\TraceableRegistry;

final class TraceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/trace.php', 'trace');

        $this->app->singleton(TraceableRegistry::class);
        $this->app->singleton(MapperResolver::class);
        $this->app->singleton(RemoteManager::class);
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        // the macro works with `route:cache`: the routes are plain controller actions with route defaults
        Router::macro('source', $this->app->make(RegistersSourceRoutes::class));

        $this->app->make(Router::class)->aliasMiddleware('trace.auth', AuthenticateTraceSource::class);

        RateLimiter::for('trace', fn (Request $request): Limit => TraceConfig::rateLimit() === 0
            ? Limit::none()
            : Limit::perMinute(TraceConfig::rateLimit())->by((string) $request->ip()));

        $this->bootDashboard();

        // one synchronous wildcard listener for every #[Traceable] model, independent of route registration
        Event::listen('eloquent.*', [TraceableEventListener::class, 'handle']);

        // the dashboard runs trace:prune and trace:triggers from a web request
        if ($this->app->runningInConsole() || TraceConfig::dashboardEnabled()) {
            $this->commands([
                InstallCommand::class,
                MakeSourceCommand::class,
                MakeMapperCommand::class,
                SyncCommand::class,
                DoctorCommand::class,
                PruneCommand::class,
                TriggersCommand::class,
            ]);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/trace.php' => config_path('trace.php')], 'trace-config');
            $this->publishesMigrations([__DIR__.'/../database/migrations/2026_10_13_000000_create_trace_source_tables.php' => database_path('migrations/2026_10_13_000000_create_trace_source_tables.php')], 'trace-source-migrations');
            $this->publishesMigrations([__DIR__.'/../database/migrations/2026_10_13_000001_create_trace_remote_tables.php' => database_path('migrations/2026_10_13_000001_create_trace_remote_tables.php')], 'trace-remote-migrations');
        }
    }

    private function bootDashboard(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'trace');

        $this->app->make(Router::class)->aliasMiddleware('trace.dashboard', AuthorizeDashboard::class);

        // the defaults keep the dashboard to local development: define the gates in a service provider to open it up
        foreach (['viewTrace', 'manageTrace'] as $ability) {
            if (! Gate::has($ability)) {
                Gate::define($ability, fn (?Authenticatable $user = null): bool => $this->app->environment('local'));
            }
        }

        if (TraceConfig::dashboardEnabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/dashboard.php');
        }
    }
}
