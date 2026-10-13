<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Vortech\Trace\Enums\RunStatus;
use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Events\SyncFailed;
use Vortech\Trace\Exceptions\CursorExpiredException;
use Vortech\Trace\Exceptions\InstanceMismatchException;
use Vortech\Trace\Exceptions\ProtocolException;
use Vortech\Trace\Exceptions\ReconcileRequiredException;
use Vortech\Trace\Exceptions\RemoteAuthenticationException;
use Vortech\Trace\Exceptions\RemoteUnavailableException;
use Vortech\Trace\Exceptions\SchemaMismatchException;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceLink;
use Vortech\Trace\Models\TraceMeta;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Tests\Support\Product;
use Vortech\Trace\Tests\Support\RemoteProduct;

it('resumes a bootstrap after a timeout on the second page without a duplicate', function (): void {
    config(['trace.source.page_size' => 2]);
    foreach (range(1, 5) as $_) {
        makeProduct();
    }

    $this->interceptor = fn ($request, int $n) => $n === 2 ? throw new ConnectionException('timeout') : null;

    expect(fn () => syncProducts())->toThrow(RemoteUnavailableException::class);

    $state = TraceSyncState::query()->firstOrFail();
    expect($state->status)->toBe(SyncStatus::Failed)
        ->and($state->cursor)->toBeNull('a half done bootstrap has no incremental cursor')
        ->and($state->bootstrap_cursor)->not->toBeNull()
        ->and(RemoteProduct::query()->count())->toBe(2, 'the first page is committed')
        ->and(TraceSyncRun::query()->firstOrFail()->status)->toBe(RunStatus::Failed);

    $this->interceptor = null;
    $this->requests = [];
    syncProducts();

    expect(RemoteProduct::query()->count())->toBe(5)
        ->and(TraceLink::query()->count())->toBe(5)
        ->and(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::Ready)
        ->and($this->requests[0])->toContain('cursor=');
});

it('does not move the incremental cursor over a failing page', function (): void {
    config(['trace.source.page_size' => 2]);
    foreach (range(1, 3) as $_) {
        makeProduct();
    }
    syncProducts();
    $cursor = TraceSyncState::query()->firstOrFail()->cursor;

    foreach (range(1, 4) as $_) {
        makeProduct();
    }
    $this->requests = [];
    $this->interceptor = fn ($request, int $n) => $n === 2 ? throw new ConnectionException('timeout') : null;

    expect(fn () => syncProducts())->toThrow(RemoteUnavailableException::class);

    $afterFailure = TraceSyncState::query()->firstOrFail()->cursor;
    expect($afterFailure)->not->toBe($cursor, 'the first page committed together with its cursor')
        ->and(RemoteProduct::query()->count())->toBe(5);

    $this->interceptor = null;
    syncProducts();

    expect(RemoteProduct::query()->count())->toBe(7)->and(TraceLink::query()->count())->toBe(7);
});

it('rolls the whole page back when a mapping fails and does not move the cursor', function (): void {
    makeProduct();
    syncProducts();
    $cursor = TraceSyncState::query()->firstOrFail()->cursor;

    makeProduct(['name' => 'Fine']);
    makeProduct(['name' => 'Fine too']);
    // a source that stopped publishing an attribute the mapper needs
    $this->interceptor = function ($request, int $n) {
        $payload = $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN])->get(parse_url($request->url(), PHP_URL_PATH).'?'.parse_url($request->url(), PHP_URL_QUERY))->json();
        unset($payload['data'][1]['attributes']['barcode']);

        return Http::response($payload);
    };

    expect(fn () => syncProducts())->toThrow(SchemaMismatchException::class, 'barcode');

    expect(RemoteProduct::query()->count())->toBe(1, 'the first record of the page rolled back with the failing one')
        ->and(TraceSyncState::query()->firstOrFail()->cursor)->toBe($cursor)
        ->and(TraceLink::query()->count())->toBe(1);
});

it('does not retry blindly on authentication errors', function (int $status, string $code): void {
    $this->interceptor = fn () => Http::response(['error' => 'x', 'message' => 'nope'], $status);

    expect(fn () => syncProducts())->toThrow(RemoteAuthenticationException::class);

    expect($this->requests)->toHaveCount(1)
        ->and(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::Failed);
})->with([[401, 'remote_unauthenticated'], [403, 'remote_forbidden']]);

it('treats 429 and 5xx as retryable and 404, 422 as protocol errors', function (): void {
    $this->interceptor = fn () => Http::response([], 429);
    expect(fn () => syncProducts())->toThrow(RemoteUnavailableException::class);

    $this->interceptor = fn () => Http::response([], 503);
    expect(fn () => syncProducts())->toThrow(RemoteUnavailableException::class);

    $this->interceptor = fn () => Http::response([], 404);
    expect(fn () => syncProducts())->toThrow(ProtocolException::class, 'no such feed');

    $this->interceptor = fn () => Http::response(['error' => 'invalid_limit', 'message' => 'bad'], 422);
    expect(fn () => syncProducts())->toThrow(ProtocolException::class, 'bad');
});

it('retries a flaky source a limited number of times', function (): void {
    config(['trace.remote.retries' => 3]);
    makeProduct();
    $this->interceptor = fn ($request, int $n) => $n < 3 ? Http::response([], 503) : null;

    $result = syncProducts();

    expect($result->created)->toBe(1)->and($this->requests)->toHaveCount(4, '2 failed attempts, then snapshot + catch-up');
});

it('fails fast on an incompatible protocol, a wrong feed and a garbage body', function (): void {
    makeProduct();

    $this->interceptor = fn () => Http::response(['data' => [], 'meta' => ['protocol' => 2, 'feed' => 'products']]);
    expect(fn () => syncProducts())->toThrow(ProtocolException::class, 'protocol');

    $this->interceptor = fn () => Http::response(['data' => [], 'meta' => ['protocol' => 1, 'feed' => 'doctors', 'instance_id' => 'x', 'schema_hash' => 'y']]);
    expect(fn () => syncProducts())->toThrow(ProtocolException::class, 'another feed');

    $this->interceptor = fn () => Http::response('not json at all');
    expect(fn () => syncProducts())->toThrow(ProtocolException::class);

    expect(RemoteProduct::query()->count())->toBe(0);
});

it('refuses a source whose instance id changed and links nothing to it', function (): void {
    makeProduct();
    syncProducts();

    TraceMeta::query()->where('key', 'instance_id')->update(['value' => 'a-completely-different-installation']);
    makeProduct();

    expect(fn () => syncProducts())->toThrow(InstanceMismatchException::class);

    expect(TraceLink::query()->count())->toBe(1)
        ->and(RemoteProduct::query()->count())->toBe(1)
        ->and(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::Failed);

    expect(fn () => reconcileProducts())->toThrow(InstanceMismatchException::class);
    expect(TraceLink::query()->count())->toBe(1);
});

it('requires a reconcile when the cursor fell out of the retention window', function (): void {
    makeProduct();
    syncProducts();

    makeProduct();
    TraceChange::query()->update(['created_at' => now()->subDays(40)]);
    $this->artisan('trace:prune')->assertSuccessful();

    expect(fn () => syncProducts())->toThrow(CursorExpiredException::class);

    $state = TraceSyncState::query()->firstOrFail();
    expect($state->status)->toBe(SyncStatus::ReconcileRequired)
        ->and($state->cursor)->not->toBeNull('the cursor is not skipped blindly');

    expect(fn () => syncProducts())->toThrow(ReconcileRequiredException::class);

    $result = reconcileProducts();

    expect($result->created)->toBe(1)
        ->and(RemoteProduct::query()->count())->toBe(2)
        ->and(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::Ready);

    makeProduct();
    expect(syncProducts()->created)->toBe(1);
});

it('requires a reconcile when the published schema changed', function (): void {
    makeProduct();
    syncProducts();
    TraceSyncState::query()->update(['schema_hash' => 'sha256:the-old-shape']);

    expect(fn () => syncProducts())->toThrow(SchemaMismatchException::class, 'schema');

    expect(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::ReconcileRequired);

    reconcileProducts();

    expect(TraceSyncState::query()->firstOrFail()->schema_hash)->toStartWith('sha256:')
        ->not->toBe('sha256:the-old-shape');
    syncProducts();
});

it('needs a reconcile when the source was reset behind the cursor', function (): void {
    makeProduct();
    makeProduct();
    syncProducts();

    TraceStream::query()->update(['version' => 0]);
    TraceChange::query()->delete();

    expect(fn () => syncProducts())->toThrow(CursorExpiredException::class);
    expect(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::ReconcileRequired);
});

it('dispatches the failed event with the exception', function (): void {
    \Event::fake([SyncFailed::class]);
    $this->interceptor = fn () => Http::response([], 401);

    try {
        syncProducts();
    } catch (RemoteAuthenticationException) {
    }

    \Event::assertDispatched(SyncFailed::class, fn (SyncFailed $event): bool => $event->exception instanceof RemoteAuthenticationException);
});

it('restarts a paused bootstrap when its snapshot cursor expired', function (): void {
    config(['trace.source.page_size' => 2]);
    foreach (range(1, 5) as $_) {
        makeProduct();
    }
    $this->interceptor = fn ($request, int $n) => $n === 2 ? throw new ConnectionException('timeout') : null;
    expect(fn () => syncProducts())->toThrow(RemoteUnavailableException::class);

    $this->interceptor = null;
    $this->travel(3)->days();
    syncProducts();

    expect(RemoteProduct::query()->count())->toBe(5)->and(TraceLink::query()->count())->toBe(5);
});

it('does not link a bootstrap-created record twice after a resume', function (): void {
    config(['trace.source.page_size' => 2]);
    foreach (range(1, 4) as $_) {
        makeProduct();
    }
    $this->interceptor = fn ($request, int $n) => $n === 3 ? throw new ConnectionException('timeout') : null;
    expect(fn () => syncProducts())->toThrow(RemoteUnavailableException::class);
    $this->interceptor = null;

    syncProducts();
    syncProducts();

    expect(TraceLink::query()->count())->toBe(4)
        ->and(TraceLink::query()->distinct()->count('model_key'))->toBe(4)
        ->and(Product::query()->count())->toBe(4);
});
