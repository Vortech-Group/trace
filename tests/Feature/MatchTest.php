<?php

declare(strict_types=1);

use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Exceptions\AmbiguousMatchException;
use Vortech\Trace\Models\TraceLink;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Tests\Support\Product;
use Vortech\Trace\Tests\Support\RemoteProduct;

it('creates a record and sets the match column create-only when nothing matches', function (): void {
    makeProduct(['code' => 'PR-7']);

    $result = syncProducts();

    expect($result->created)->toBe(1)
        ->and(RemoteProduct::query()->firstOrFail()->product_code)->toBe('PR-7');
});

it('links the one existing record that matches instead of duplicating it', function (): void {
    RemoteProduct::query()->create(['product_code' => 'PR-7', 'name' => 'Old name', 'local_note' => 'keep me']);
    makeProduct(['code' => 'PR-7', 'name' => 'New name']);

    $result = syncProducts();
    $local = RemoteProduct::query()->firstOrFail();

    expect($result->created)->toBe(0)
        ->and($result->updated)->toBe(1)
        ->and(RemoteProduct::query()->count())->toBe(1)
        ->and($local->name)->toBe('New name')
        ->and($local->local_note)->toBe('keep me')
        ->and(TraceLink::query()->firstOrFail()->model_key)->toBe((string) $local->id);
});

it('stops with a clear error on an ambiguous match and picks nothing', function (): void {
    RemoteProduct::query()->create(['product_code' => 'PR-7', 'name' => 'A']);
    RemoteProduct::query()->create(['product_code' => 'PR-7', 'name' => 'B']);
    makeProduct(['code' => 'PR-7', 'name' => 'Source']);

    expect(fn () => syncProducts())->toThrow(AmbiguousMatchException::class);

    expect(RemoteProduct::query()->pluck('name')->sort()->values()->all())->toBe(['A', 'B'])
        ->and(TraceLink::query()->count())->toBe(0)
        ->and(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::Failed)
        ->and(TraceSyncState::query()->firstOrFail()->last_error)->toContain('ambiguous');
});

it('does not match on a null source value', function (): void {
    RemoteProduct::query()->create(['product_code' => null, 'name' => 'Local without code']);
    makeProduct(['code' => null, 'name' => 'Source without code']);

    $result = syncProducts();

    expect($result->created)->toBe(1)->and(RemoteProduct::query()->count())->toBe(2);
});

it('never re-runs the match once a link exists', function (): void {
    makeProduct(['code' => 'PR-1']);
    syncProducts();
    $local = RemoteProduct::query()->firstOrFail();

    // another local record now carries the business key the source still publishes
    RemoteProduct::query()->create(['product_code' => 'PR-1', 'name' => 'Impostor']);
    inTx(fn () => Product::query()->firstOrFail()->update(['name' => 'Renamed at source']));
    syncProducts();

    expect(TraceLink::query()->firstOrFail()->model_key)->toBe((string) $local->id)
        ->and($local->refresh()->name)->toBe('Renamed at source')
        ->and(RemoteProduct::query()->where('name', 'Impostor')->firstOrFail()->name)->toBe('Impostor');
});

it('restores a matched local record that was soft deleted', function (): void {
    $existing = RemoteProduct::query()->create(['product_code' => 'PR-7', 'name' => 'Old']);
    $existing->delete();
    makeProduct(['code' => 'PR-7']);

    $result = syncProducts();

    expect($result->restored)->toBe(1)->and(RemoteProduct::query()->count())->toBe(1);
});
