<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vortech\Trace\Exceptions\ConnectionMismatchException;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Tests\Support\Product;
use Vortech\Trace\Tests\Support\UntrackedProduct;

it('logs created, updated and deleted events of traceable models', function (): void {
    $product = makeProduct();
    inTx(fn () => $product->update(['name' => 'Renamed']));
    inTx(fn () => $product->delete());

    expect(TraceChange::query()->orderBy('version')->get()->map(fn (TraceChange $c): string => "{$c->version}:{$c->model_key}:{$c->operation->value}")->all())
        ->toBe(['1:1:changed', '2:1:changed', '3:1:deleted'])
        ->and(TraceStream::query()->firstOrFail()->version)->toBe(3)
        ->and(TraceStream::query()->firstOrFail()->model_type)->toBe(Product::class);
});

it('ignores models without the marker', function (): void {
    inTx(fn () => UntrackedProduct::query()->create(['name' => 'Nobody tracks me']));

    expect(TraceChange::query()->count())->toBe(0)->and(TraceStream::query()->count())->toBe(0);
});

it('needs no trait on the model', function (): void {
    expect(array_keys(class_uses(Product::class)))->toBe([SoftDeletes::class])
        ->and(class_implements(Product::class))->not->toContain('Vortech\\Trace\\Contracts\\Tracked');
});

it('does not log a change that rolled back', function (): void {
    try {
        inTx(function (): void {
            makeProduct();
            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException) {
    }

    expect(Product::query()->count())->toBe(0)
        ->and(TraceChange::query()->count())->toBe(0)
        ->and(TraceStream::query()->count())->toBe(0, 'the stream row and its version roll back too');
});

it('hands out consecutive versions per stream', function (): void {
    inTx(function (): void {
        makeProduct();
        makeProduct();
        makeProduct();
    });

    expect(TraceChange::query()->orderBy('version')->pluck('version')->all())->toBe([1, 2, 3]);
});

it('does not log writes that fire no model event', function (): void {
    makeProduct();
    Product::query()->update(['name' => 'mass update']);
    Product::query()->where('id', 1)->delete();
    DB::table('source_products')->insert(['name' => 'raw', 'is_active' => true]);

    expect(TraceChange::query()->count())->toBe(1);
});

it('keeps the write and logs best effort outside a transaction', function (): void {
    Product::query()->create(['name' => 'No explicit transaction']);

    expect(Product::query()->count())->toBe(1)->and(TraceChange::query()->count())->toBe(1);
});

it('reports instead of hiding a failed log write outside a transaction', function (): void {
    Schema::drop('trace_changes');
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (\Throwable $e) use (&$reported): void {
        $reported[] = $e;
    });

    Product::query()->create(['name' => 'Still saved']);

    expect(Product::query()->count())->toBe(1)->and($reported)->not->toBeEmpty();
});

it('fails loudly, and rolls the write back, when the log write fails inside a transaction', function (): void {
    Schema::drop('trace_changes');

    expect(fn () => inTx(fn () => Product::query()->create(['name' => 'x'])))->toThrow(QueryException::class)
        ->and(Product::query()->count())->toBe(0);
});

it('fails fast when the model writes on another connection than the trace tables', function (): void {
    config(['database.connections.other' => config('database.connections.testing'), 'trace.connection' => 'other']);

    expect(fn () => Product::query()->create(['name' => 'x']))->toThrow(ConnectionMismatchException::class);
});
