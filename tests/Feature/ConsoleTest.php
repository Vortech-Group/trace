<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Vortech\Trace\Jobs\TraceSyncJob;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Tests\Support\RemoteProduct;

afterEach(function (): void {
    File::delete([config_path('trace.php'), app_path('Trace/Sources/ProductSource.php'), app_path('Trace/Mappers/ProductMapper.php')]);
    File::deleteDirectory(app_path('Trace'));

    foreach (File::glob(database_path('migrations/*_create_trace_*_tables.php')) as $migration) {
        File::delete($migration);
    }
});

it('fails without a side when it cannot ask', function (): void {
    $this->artisan('trace:install', ['--no-interaction' => true])->assertFailed();
});

it('asks which side to install', function (): void {
    $this->artisan('trace:install')
        ->expectsChoice('Which side does this application play?', ['remote'], ['source' => 'Source: publishes its data', 'remote' => 'Remote: receives data from a source'])
        ->assertSuccessful();

    expect(File::glob(database_path('migrations/*_create_trace_remote_tables.php')))->toHaveCount(1)
        ->and(File::glob(database_path('migrations/*_create_trace_source_tables.php')))->toHaveCount(0);
});

it('installs the source side', function (): void {
    $this->artisan('trace:install', ['--source' => true])->expectsOutputToContain('Source instance id')->assertSuccessful();

    expect(File::glob(database_path('migrations/*_create_trace_source_tables.php')))->toHaveCount(1)
        ->and(File::glob(database_path('migrations/*_create_trace_remote_tables.php')))->toHaveCount(0)
        ->and(File::exists(config_path('trace.php')))->toBeTrue();

    $this->artisan('trace:install', ['--source' => true])->expectsOutputToContain('already published')->assertSuccessful();
});

it('installs the remote side', function (): void {
    $this->artisan('trace:install', ['--remote' => true])->assertSuccessful();

    expect(File::glob(database_path('migrations/*_create_trace_remote_tables.php')))->toHaveCount(1);
});

it('warns when the source token is missing', function (): void {
    config(['trace.source.token' => null]);

    $this->artisan('trace:install', ['--source' => true])->expectsOutputToContain('TRACE_SOURCE_TOKEN is not set')->assertSuccessful();
});

it('generates a source and a mapper', function (): void {
    $this->artisan('trace:make-source', ['name' => 'ProductSource'])->assertSuccessful();
    $this->artisan('trace:make-mapper', ['name' => 'ProductMapper'])->assertSuccessful();

    expect(File::get(app_path('Trace/Sources/ProductSource.php')))->toContain('namespace App\\Trace\\Sources;', 'final class ProductSource extends Source')
        ->and(File::get(app_path('Trace/Mappers/ProductMapper.php')))->toContain('namespace App\\Trace\\Mappers;', 'final class ProductMapper extends Mapper');
});

it('syncs from the command line', function (): void {
    makeProduct();

    $this->artisan('trace:sync', ['model' => RemoteProduct::class])->expectsOutputToContain('bootstrap finished')->assertSuccessful();
    expect(RemoteProduct::query()->count())->toBe(1);

    $this->artisan('trace:sync', ['model' => RemoteProduct::class, '--full' => true])->expectsOutputToContain('reconcile finished')->assertSuccessful();
    expect(TraceSyncRun::query()->count())->toBe(2);
});

it('queues a sync from the command line', function (): void {
    Queue::fake();

    $this->artisan('trace:sync', ['model' => RemoteProduct::class, '--queue' => true])->assertSuccessful();

    Queue::assertPushed(TraceSyncJob::class);
});

it('reports a failed sync and a bad model with a failure exit code', function (): void {
    $this->artisan('trace:sync', ['model' => 'Nope\\Missing'])->assertFailed();
    $this->artisan('trace:sync', ['model' => \stdClass::class])->assertFailed();

    $this->interceptor = fn () => \Http::response([], 401);
    $this->artisan('trace:sync', ['model' => RemoteProduct::class])->expectsOutputToContain('rejected the token')->assertFailed();
});

it('passes the doctor on a healthy setup', function (): void {
    makeProduct();
    installTriggers();
    config(['trace.remote.models' => [RemoteProduct::class]]);

    $this->artisan('trace:doctor')->expectsOutputToContain('Feed [products] is valid')->assertSuccessful();
});

it('fails the doctor on a broken setup', function (): void {
    config(['trace.source.token' => null, 'trace.remote.models' => [RemoteProduct::class], 'trace.remote.default' => ['url' => null, 'token' => null]]);

    $this->artisan('trace:doctor')->expectsOutputToContain('TRACE_SOURCE_TOKEN is not set')->assertFailed();
});

it('prunes old changes and moves the retention boundary', function (): void {
    makeProduct();
    makeProduct();
    makeProduct();
    TraceChange::query()->whereIn('version', [1, 2])->update(['created_at' => now()->subDays(31)]);
    TraceSyncRun::query()->create(['feed' => 'products', 'model_type' => 'x', 'mode' => 'bootstrap', 'status' => 'completed', 'created_at' => now()->subDays(60)]);

    $this->artisan('trace:prune')->expectsOutputToContain('Pruned 2 change log rows and 1 sync runs')->assertSuccessful();

    expect(TraceChange::query()->pluck('version')->all())->toBe([3])
        ->and(TraceStream::query()->firstOrFail()->pruned_version)->toBe(2);
});
