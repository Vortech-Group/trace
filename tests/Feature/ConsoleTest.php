<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Vortech\Trace\Jobs\TraceSyncJob;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Tests\Support\RemoteClinic;

afterEach(function (): void {
    File::delete([config_path('trace.php'), app_path('Trace/Sources/ClinicSource.php'), app_path('Trace/Mappers/ClinicMapper.php')]);
    File::deleteDirectory(app_path('Trace'));

    foreach (File::glob(database_path('migrations/*_create_trace_*_tables.php')) as $migration) {
        File::delete($migration);
    }
});

it('asks for a side when installing', function (): void {
    $this->artisan('trace:install')->assertFailed();
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
    $this->artisan('trace:make-source', ['name' => 'ClinicSource'])->assertSuccessful();
    $this->artisan('trace:make-mapper', ['name' => 'ClinicMapper'])->assertSuccessful();

    expect(File::get(app_path('Trace/Sources/ClinicSource.php')))->toContain('namespace App\\Trace\\Sources;', 'final class ClinicSource extends Source')
        ->and(File::get(app_path('Trace/Mappers/ClinicMapper.php')))->toContain('namespace App\\Trace\\Mappers;', 'final class ClinicMapper extends Mapper');
});

it('syncs from the command line', function (): void {
    makeClinic();

    $this->artisan('trace:sync', ['model' => RemoteClinic::class])->expectsOutputToContain('bootstrap finished')->assertSuccessful();
    expect(RemoteClinic::query()->count())->toBe(1);

    $this->artisan('trace:sync', ['model' => RemoteClinic::class, '--full' => true])->expectsOutputToContain('reconcile finished')->assertSuccessful();
    expect(TraceSyncRun::query()->count())->toBe(2);
});

it('queues a sync from the command line', function (): void {
    Queue::fake();

    $this->artisan('trace:sync', ['model' => RemoteClinic::class, '--queue' => true])->assertSuccessful();

    Queue::assertPushed(TraceSyncJob::class);
});

it('reports a failed sync and a bad model with a failure exit code', function (): void {
    $this->artisan('trace:sync', ['model' => 'Nope\\Missing'])->assertFailed();
    $this->artisan('trace:sync', ['model' => \stdClass::class])->assertFailed();

    $this->interceptor = fn () => \Http::response([], 401);
    $this->artisan('trace:sync', ['model' => RemoteClinic::class])->expectsOutputToContain('rejected the token')->assertFailed();
});

it('passes the doctor on a healthy setup', function (): void {
    makeClinic();
    config(['trace.remote.models' => [RemoteClinic::class]]);

    $this->artisan('trace:doctor')->expectsOutputToContain('Feed [clinics] is valid')->assertSuccessful();
});

it('fails the doctor on a broken setup', function (): void {
    config(['trace.source.token' => null, 'trace.remote.models' => [RemoteClinic::class], 'trace.remote.default' => ['url' => null, 'token' => null]]);

    $this->artisan('trace:doctor')->expectsOutputToContain('TRACE_SOURCE_TOKEN is not set')->assertFailed();
});

it('prunes old changes and moves the retention boundary', function (): void {
    makeClinic();
    makeClinic();
    makeClinic();
    TraceChange::query()->whereIn('version', [1, 2])->update(['created_at' => now()->subDays(31)]);
    TraceSyncRun::query()->create(['feed' => 'clinics', 'model_type' => 'x', 'mode' => 'bootstrap', 'status' => 'completed', 'created_at' => now()->subDays(60)]);

    $this->artisan('trace:prune')->expectsOutputToContain('Pruned 2 change log rows and 1 sync runs')->assertSuccessful();

    expect(TraceChange::query()->pluck('version')->all())->toBe([3])
        ->and(TraceStream::query()->firstOrFail()->pruned_version)->toBe(2);
});
