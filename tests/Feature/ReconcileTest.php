<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Vortech\Trace\Enums\SyncMode;
use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Exceptions\DeletionGuardException;
use Vortech\Trace\Exceptions\RemoteUnavailableException;
use Vortech\Trace\Facades\Remote;
use Vortech\Trace\Models\TraceLink;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Tests\Support\Product;
use Vortech\Trace\Tests\Support\RemoteProduct;

it('misses writes without model events until a full reconcile repairs them', function (): void {
    foreach (range(1, 3) as $_) {
        makeProduct();
    }
    syncProducts();

    Product::query()->update(['name' => 'Silently renamed']);
    DB::table('source_products')->where('id', 3)->delete();
    DB::table('source_products')->insert(['code' => 'RAW', 'name' => 'Imported raw', 'is_active' => true]);

    $incremental = syncProducts();
    expect($incremental->updated + $incremental->created + $incremental->deleted)->toBe(0)
        ->and(RemoteProduct::query()->where('name', 'Silently renamed')->count())->toBe(0);

    $reconcile = reconcileProducts();

    expect($reconcile->mode)->toBe(SyncMode::Reconcile)
        ->and($reconcile->updated)->toBe(2)
        ->and($reconcile->created)->toBe(1)
        ->and($reconcile->deleted)->toBe(1)
        ->and(RemoteProduct::query()->where('name', 'Silently renamed')->count())->toBe(2)
        ->and(RemoteProduct::onlyTrashed()->count())->toBe(1)
        ->and(RemoteProduct::query()->where('name', 'Imported raw')->count())->toBe(1);
});

it('keeps local records that Trace does not own, also across a reconcile', function (): void {
    RemoteProduct::query()->create(['product_code' => 'LOCAL-ONLY', 'name' => 'Mine']);
    makeProduct();
    syncProducts();
    DB::table('source_products')->delete();

    $reconcile = reconcileProducts();

    expect($reconcile->deleted)->toBe(1)
        ->and(RemoteProduct::query()->where('product_code', 'LOCAL-ONLY')->count())->toBe(1)
        ->and(RemoteProduct::query()->count())->toBe(1);
});

it('refuses an unusually large vanish and soft deletes nothing', function (): void {
    config(['trace.safety.min_records_for_ratio_guard' => 4, 'trace.safety.max_missing_deletion_ratio' => 0.2]);
    foreach (range(1, 5) as $_) {
        makeProduct();
    }
    syncProducts();
    DB::table('source_products')->whereIn('id', [1, 2, 3])->delete();

    expect(fn () => reconcileProducts())->toThrow(DeletionGuardException::class);

    expect(RemoteProduct::query()->count())->toBe(5)
        ->and(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::ReconcileRequired);

    $result = Remote::for(RemoteProduct::class)->reconcile()->allowMassDeletion()->run();

    expect($result->deleted)->toBe(3)
        ->and(RemoteProduct::query()->count())->toBe(2)
        ->and(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::Ready);
});

it('does not delete anything by absence when the traversal fails half way', function (): void {
    config(['trace.source.page_size' => 2]);
    foreach (range(1, 4) as $_) {
        makeProduct();
    }
    syncProducts();
    DB::table('source_products')->where('id', 1)->delete();

    $this->requests = [];
    $this->interceptor = fn ($request, int $n) => $n === 2 ? throw new ConnectionException('timeout') : null;

    expect(fn () => reconcileProducts())->toThrow(RemoteUnavailableException::class);

    expect(RemoteProduct::query()->count())->toBe(4)
        ->and(RemoteProduct::onlyTrashed()->count())->toBe(0);
});

it('keeps the links of the previous state when a reconcile fails', function (): void {
    makeProduct();
    syncProducts();
    $cursor = TraceSyncState::query()->firstOrFail()->cursor;

    $this->interceptor = fn () => \Http::response(['error' => 'x'], 500);

    expect(fn () => reconcileProducts())->toThrow(RemoteUnavailableException::class)
        ->and(TraceSyncState::query()->firstOrFail()->cursor)->toBe($cursor)
        ->and(TraceLink::query()->count())->toBe(1);
});
