<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vortech\Trace\Enums\RunStatus;
use Vortech\Trace\Enums\SyncMode;
use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Events\SyncCompleted;
use Vortech\Trace\Events\SyncStarted;
use Vortech\Trace\Facades\Remote;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceLink;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Tests\Support\Product;
use Vortech\Trace\Tests\Support\RemoteProduct;

/** @return list<string> every UPDATE statement that touched the remote products table */
function countRemoteUpdates(\Closure $callback): int
{
    $updates = 0;
    DB::listen(function ($query) use (&$updates): void {
        if (str_starts_with(strtolower($query->sql), 'update "remote_products"')) {
            $updates++;
        }
    });

    $callback();

    return $updates;
}

it('bootstraps 1000 source records into exactly 1000 links', function (): void {
    config(['trace.source.page_size' => 500]);
    inTx(function (): void {
        foreach (range(1, 1000) as $_) {
            makeProduct();
        }
    });

    $result = syncProducts();

    expect($result->mode)->toBe(SyncMode::Bootstrap)
        ->and($result->created)->toBe(1000)
        ->and(RemoteProduct::query()->count())->toBe(1000)
        ->and(TraceLink::query()->count())->toBe(1000)
        ->and(TraceLink::query()->distinct()->count('remote_key'))->toBe(1000)
        ->and($result->cursor)->toBeString()
        ->and(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::Ready)
        ->and(TraceSyncState::query()->firstOrFail()->bootstrap_watermark)->toBeNull();
});

it('does not duplicate rows or issue needless updates when run again', function (): void {
    foreach (range(1, 5) as $_) {
        makeProduct();
    }
    syncProducts();

    $updates = countRemoteUpdates(function (): void {
        $second = syncProducts();
        expect($second->mode)->toBe(SyncMode::Incremental)
            ->and($second->created + $second->updated + $second->deleted)->toBe(0);
    });

    expect($updates)->toBe(0)
        ->and(RemoteProduct::query()->count())->toBe(5)
        ->and(TraceLink::query()->count())->toBe(5);
});

it('touches only the record that changed in an incremental run', function (): void {
    makeProduct(['name' => 'One']);
    $two = makeProduct(['name' => 'Two']);
    makeProduct(['name' => 'Three']);
    syncProducts();
    $this->requests = [];

    inTx(fn () => $two->update(['name' => 'Two (new)']));
    $updates = countRemoteUpdates(function (): void {
        $result = syncProducts();
        expect($result->updated)->toBe(1)->and($result->created)->toBe(0)->and($result->unchanged)->toBe(0);
    });

    expect($updates)->toBe(1)
        ->and(RemoteProduct::query()->where('name', 'Two (new)')->count())->toBe(1)
        ->and(collect($this->requests)->every(fn (string $uri): bool => str_contains($uri, '/changes')))->toBeTrue();
});

it('writes renamed attributes to the mapped column and leaves other local columns alone', function (): void {
    makeProduct(['code' => 'PR-1', 'barcode' => '+361', 'is_active' => true, 'slug' => ' Info-Slug ']);
    syncProducts();
    $local = RemoteProduct::query()->firstOrFail();
    $local->forceFill(['local_note' => 'mine'])->save();

    inTx(fn () => Product::query()->firstOrFail()->update(['barcode' => '+362', 'is_active' => false]));
    syncProducts();

    $local->refresh();
    expect($local->ean)->toBe('+362')
        ->and($local->active)->toBeFalse()
        ->and($local->local_note)->toBe('mine')
        ->and($local->slug)->toBe('info-slug', 'transform() lower-cased and trimmed it');
});

it('keeps a local business key that is not mapped', function (): void {
    makeProduct(['code' => 'PR-1']);
    syncProducts();

    inTx(fn () => Product::query()->firstOrFail()->update(['code' => 'PR-RENAMED', 'name' => 'Changed']));
    syncProducts();

    expect(RemoteProduct::query()->count())->toBe(1)
        ->and(RemoteProduct::query()->firstOrFail()->product_code)->toBe('PR-1')
        ->and(RemoteProduct::query()->firstOrFail()->name)->toBe('Changed');
});

it('soft deletes locally when the source soft deletes, and restores when it restores', function (): void {
    $product = makeProduct();
    syncProducts();

    inTx(fn () => $product->delete());
    $deleted = syncProducts();

    expect($deleted->deleted)->toBe(1)
        ->and(RemoteProduct::query()->count())->toBe(0)
        ->and(RemoteProduct::withTrashed()->count())->toBe(1);

    inTx(fn () => $product->restore());
    $restored = syncProducts();

    expect($restored->restored)->toBe(1)
        ->and(RemoteProduct::query()->count())->toBe(1)
        ->and(TraceLink::query()->count())->toBe(1);
});

it('turns a source force delete into a tombstone and a local soft delete', function (): void {
    $product = makeProduct();
    syncProducts();

    inTx(fn () => $product->forceDelete());
    $result = syncProducts();

    expect($result->deleted)->toBe(1)
        ->and(RemoteProduct::query()->count())->toBe(0)
        ->and(RemoteProduct::onlyTrashed()->count())->toBe(1)
        ->and(TraceChange::query()->where('operation', 'deleted')->count())->toBe(1);
});

it('never hard deletes anything locally', function (): void {
    $product = makeProduct();
    syncProducts();
    inTx(fn () => $product->forceDelete());
    syncProducts();

    expect(DB::table('remote_products')->count())->toBe(1);
});

it('ignores a tombstone for a key it never linked', function (): void {
    $product = makeProduct();
    inTx(fn () => $product->forceDelete());
    RemoteProduct::query()->create(['product_code' => 'LOCAL', 'name' => 'Local only']);

    $result = syncProducts();

    expect($result->deleted)->toBe(0)->and(RemoteProduct::query()->count())->toBe(1);
});

it('replays the changes made while a long bootstrap was running', function (): void {
    config(['trace.source.page_size' => 2]);
    foreach (range(1, 4) as $_) {
        makeProduct();
    }

    $this->interceptor = function ($request, int $n) {
        if ($n === 2) {
            // between page 1 and page 2: an imported record changes, another vanishes, a new one appears
            inTx(fn () => Product::query()->findOrFail(1)->update(['name' => 'Changed meanwhile']));
            inTx(fn () => Product::query()->findOrFail(2)->delete());
            makeProduct(['name' => 'Born meanwhile']);
        }

        return null;
    };

    syncProducts();

    $remote = RemoteProduct::query()->orderBy('id')->pluck('name')->all();
    $source = Product::query()->orderBy('id')->pluck('name')->all();

    expect(collect($remote)->sort()->values()->all())->toBe(collect($source)->sort()->values()->all())
        ->and(RemoteProduct::onlyTrashed()->count())->toBe(1)
        ->and(RemoteProduct::query()->count())->toBe(4);
});

it('records runs, events and counters', function (): void {
    \Event::fake([SyncStarted::class, SyncCompleted::class]);
    makeProduct();

    $result = syncProducts();
    $run = TraceSyncRun::query()->firstOrFail();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->created)->toBe(1)
        ->and($run->mode)->toBe(SyncMode::Bootstrap)
        ->and($run->finished_at)->not->toBeNull()
        ->and($result->runId)->toBe($run->id)
        ->and($result->durationMs)->toBeInt();

    \Event::assertDispatched(SyncStarted::class);
    \Event::assertDispatched(SyncCompleted::class, fn (SyncCompleted $event): bool => $event->result->created === 1);
});

it('building a sync has no side effect', function (): void {
    makeProduct();

    Remote::for(RemoteProduct::class)->sync()->onQueue('integrations');
    Remote::for(RemoteProduct::class)->reconcile();

    expect($this->requests)->toBe([])
        ->and(TraceSyncState::query()->count())->toBe(0)
        ->and(RemoteProduct::query()->count())->toBe(0);
});

it('does not log record values', function (): void {
    \Log::spy();
    makeProduct(['name' => 'Highly Sensitive Name']);
    syncProducts();

    \Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'trace.sync.completed' && ! str_contains((string) json_encode($context), 'Highly Sensitive Name'));
});
