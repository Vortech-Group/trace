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
use Vortech\Trace\Tests\Support\RemoteProduct;

it('dispatches a job that carries only scalars', function (): void {
    Queue::fake();

    $pending = Remote::for(RemoteProduct::class)->sync()->dispatch();

    expect($pending)->toBeInstanceOf(PendingDispatch::class)->not->toBeInstanceOf(SyncResult::class);
    unset($pending);

    Queue::assertPushed(TraceSyncJob::class, function (TraceSyncJob $job): bool {
        $payload = (array) json_decode((string) json_encode(get_object_vars($job)), true);

        return $job->model === RemoteProduct::class
            && collect($payload)->only(['model', 'reconcile', 'allowMassDeletion'])->every(fn ($value): bool => is_scalar($value));
    });
});

it('uses the configured queue and honours onQueue and onConnection', function (): void {
    Queue::fake();
    config(['trace.remote.queue' => 'trace-default']);

    Remote::for(RemoteProduct::class)->sync()->dispatch();
    Remote::for(RemoteProduct::class)->sync()->onQueue('integrations')->dispatch();
    Remote::for(RemoteProduct::class)->sync()->onQueue('integrations')->onConnection('redis')->dispatch();

    Queue::assertPushedOn('trace-default', TraceSyncJob::class);
    Queue::assertPushedOn('integrations', TraceSyncJob::class);
    Queue::assertPushed(TraceSyncJob::class, fn (TraceSyncJob $job): bool => $job->connection === 'redis');
});

it('syncs when the job runs, and a repeat is harmless', function (): void {
    makeProduct();
    makeProduct();

    Remote::for(RemoteProduct::class)->sync()->dispatch();
    Remote::for(RemoteProduct::class)->sync()->dispatch();

    expect(RemoteProduct::query()->count())->toBe(2)->and(TraceLink::query()->count())->toBe(2);
});

it('lets only one run be active per model', function (): void {
    makeProduct();
    $definition = app(MapperResolver::class)->resolve(RemoteProduct::class);
    $held = Cache::lock(SyncLock::key($definition), 60);
    expect($held->get())->toBeTrue();

    expect(fn () => syncProducts())->toThrow(SyncAlreadyRunningException::class)
        ->and(RemoteProduct::query()->count())->toBe(0)
        ->and($this->requests)->toBe([]);

    $job = new TraceSyncJob(RemoteProduct::class);
    $job->handle(app(SyncEngine::class));
    expect(RemoteProduct::query()->count())->toBe(0, 'a job that finds a running sync leaves the work to it');

    $held->release();
    $job->handle(app(SyncEngine::class));
    expect(RemoteProduct::query()->count())->toBe(1);
});

it('releases the lock after a failure so the retry can run', function (): void {
    makeProduct();
    $this->interceptor = fn () => Http::response([], 503);

    expect(fn () => syncProducts())->toThrow(RemoteUnavailableException::class);

    $this->interceptor = null;
    expect(syncProducts()->created)->toBe(1);
});

it('lets the queue retry what may heal and fails what cannot', function (): void {
    makeProduct();
    $job = new TraceSyncJob(RemoteProduct::class);

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
    makeProduct();
    Cache::shouldReceive('getStore')->andReturn(new \stdClass);

    expect(fn () => syncProducts())->toThrow(InvalidConfigurationException::class, 'atomic locks');
});
