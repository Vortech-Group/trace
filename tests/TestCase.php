<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Vortech\Trace\Source;
use Vortech\Trace\Tests\Support\Clinic;
use Vortech\Trace\Tests\Support\ClinicSource;
use Vortech\Trace\Tests\Support\DbClinic;
use Vortech\Trace\TraceServiceProvider;

abstract class TestCase extends Orchestra
{
    public const string TOKEN = 'secret-token-for-tests';

    /** @var list<string> every request the remote sent to the in-process source, path and query */
    public array $requests = [];

    /** @var (Closure(Request, int): (PromiseInterface|null))|null decides about a request before the source sees it */
    public ?Closure $interceptor = null;

    protected function getPackageProviders($app): array
    {
        return [TraceServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('trace.source.token', self::TOKEN);
        $app['config']->set('trace.remote.default', ['url' => 'https://source.test/api', 'token' => self::TOKEN]);
        $app['config']->set('trace.remote.retries', 1);
        $app['config']->set('trace.source.rate_limit', 0);
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->prefix('api')->group(function (): void {
            $router = app(Router::class);
            $router->source('clinics', ClinicSource::class);
            $router->source('clinics-by-model', Clinic::class);
            $router->source('db-clinics', DbClinic::class);
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('source_clinics', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('internal_note')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('db_clinics', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->string('internal_note')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('remote_clinics', function (Blueprint $table): void {
            $table->id();
            $table->string('clinic_code')->nullable();
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->boolean('active')->default(false);
            $table->string('local_note')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Source::flushValidated();
    }

    /** Lets the remote talk to the source of this very application, through the real HTTP kernel. */
    protected function bridgeSource(): void
    {
        Http::fake(function (Request $request) {
            $this->requests[] = $this->localUri($request);

            $intercepted = $this->interceptor instanceof Closure ? ($this->interceptor)($request, count($this->requests)) : null;

            if ($intercepted !== null) {
                return $intercepted;
            }

            $response = $this->withHeaders(['Authorization' => $request->header('Authorization')[0] ?? ''])->get($this->localUri($request));

            return Http::response((string) $response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json', 'X-Trace-Instance' => (string) $response->headers->get('X-Trace-Instance')]);
        });
    }

    private function localUri(Request $request): string
    {
        $parts = parse_url($request->url());

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
