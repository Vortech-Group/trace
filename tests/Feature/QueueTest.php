<?php

declare(strict_types=1);

use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Vortech\Trace\Data\SyncResult;
use Vortech\Trace\Exceptions\InvalidConfigurationException;
use Vortech\Trace\Exceptions\RemoteUnavailableException;
use Vortech\Trace\Exceptions\SyncAlreadyRunningException;
use Vortech\Trace\Facades\Remote;
use Vortech\Trace\Jobs\TraceSyncJob;
use Vortech\Trace\Models\TraceLink;
use Vortech\Trace\Remote\MapperResolver;
use Vortech\Trace\Remote\SyncEngine;
use Vortech\Trace\Remote\SyncLock;
use Vortech\Trace\Tests\Support\RemoteClinic;

it('dispatches a job that carries only scalars', function (): void {
    Queue::fake();

    $pending = Remote::for(RemoteClinic::class)->sync()->dispatch();

    expect($pending)->toBeInstanceOf(PendingDispatch::class)->not->toBeInstanceOf(SyncResult::class);
    unset($pending);

    Queue::assertPushed(TraceSyncJob::class, function (TraceSyncJob $job): bool {
        $payload = (array) json_decode((string) json_encode(get_object_vars($job)), true);

        return $job->model === RemoteClinic::class
            && collect($payload)->only(['model', 'reconcile', 'allowMassDeletion'])->every(fn ($value): bool => is_scalar($value));
    });
});

it('uses the configured queue and honours onQueue and onConnection', function (): void {
    Queue::fake();
    config(['trace.remote.queue' => 'trace-default']);

    Remote::for(RemoteClinic::class)->sync()->dispatch();
    Remote::for(RemoteClinic::class)->sync()->onQueue('integrations')->dispatch();
    Remote::for(RemoteClinic::class)->sync()->onQueue('integrations')->onConnection('redis')->dispatch();

    Queue::assertPushedOn('trace-default', TraceSyncJob::class);
    Queue::assertPushedOn('integrations', TraceSyncJob::class);
    Queue::assertPushed(TraceSyncJob::class, fn (TraceSyncJob $job): bool => $job->connection === 'redis');
});

it('syncs when the job runs, and a repeat is harmless', function (): void {
    makeClinic();
    makeClinic();

    Remote::for(RemoteClinic::class)->sync()->dispatch();
    Remote::for(RemoteClinic::class)->sync()->dispatch();

    expect(RemoteClinic::query()->count())->toBe(2)->and(TraceLink::query()->count())->toBe(2);
});

it('lets only one run be active per model', function (): void {
    makeClinic();
    $definition = app(MapperResolver::class)->resolve(RemoteClinic::class);
    $held = Cache::lock(SyncLock::key($definition), 60);
    expect($held->get())->toBeTrue();

    expect(fn () => syncClinics())->toThrow(SyncAlreadyRunningException::class)
        ->and(RemoteClinic::query()->count())->toBe(0)
        ->and($this->requests)->toBe([]);

    $job = new TraceSyncJob(RemoteClinic::class);
    $job->handle(app(SyncEngine::class));
    expect(RemoteClinic::query()->count())->toBe(0, 'a job that finds a running sync leaves the work to it');

    $held->release();
    $job->handle(app(SyncEngine::class));
    expect(RemoteClinic::query()->count())->toBe(1);
});

it('releases the lock after a failure so the retry can run', function (): void {
    makeClinic();
    $this->interceptor = fn () => Http::response([], 503);

    expect(fn () => syncClinics())->toThrow(RemoteUnavailableException::class);

    $this->interceptor = null;
    expect(syncClinics()->created)->toBe(1);
});

it('lets the queue retry what may heal and fails what cannot', function (): void {
    makeClinic();
    $job = new TraceSyncJob(RemoteClinic::class);

    $this->interceptor = fn () => Http::response([], 503);
    expect(fn () => $job->handle(app(SyncEngine::class)))->toThrow(RemoteUnavailableException::class);

    $this->interceptor = fn () => Http::response([], 401);
    $job->handle(app(SyncEngine::class));
    expect(true)->toBeTrue('an authentication error is not rethrown: the job fails once, without retries');

    expect($job->tries)->toBe(1)
        ->and($job->backoff())->toBe([10, 60, 300])
        ->and($job->timeout)->toBeLessThan(config('trace.remote.lock_ttl'));
});

it('rejects a lock store without atomic locks', function (): void {
    makeClinic();
    Cache::shouldReceive('getStore')->andReturn(new \stdClass);

    expect(fn () => syncClinics())->toThrow(InvalidConfigurationException::class, 'atomic locks');
});
