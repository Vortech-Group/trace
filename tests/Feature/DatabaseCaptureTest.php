<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vortech\Trace\Capture\TriggerManager;
use Vortech\Trace\Enums\TriggerStatus;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceMeta;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Tests\Support\DbProduct;
use Vortech\Trace\Tests\Support\Product;
use Vortech\Trace\Tests\TestCase;

/** @return list<string> version:key:operation */
function log_of(): array
{
    return TraceChange::query()->orderBy('version')->get()->map(fn (TraceChange $c): string => "{$c->version}:{$c->model_key}:{$c->operation->value}")->all();
}

it('logs writes that fire no model event once the triggers are installed', function (): void {
    installTriggers();

    DB::table('db_products')->insert(['name' => 'Raw', 'code' => 'RAW-1']);
    DB::table('db_products')->where('code', 'RAW-1')->update(['name' => 'Raw renamed']);
    DB::table('db_products')->where('code', 'RAW-1')->update(['deleted_at' => now()]);
    DB::table('db_products')->where('code', 'RAW-1')->update(['deleted_at' => null]);
    DB::table('db_products')->where('code', 'RAW-1')->delete();

    expect(log_of())->toBe(['1:1:changed', '2:1:changed', '3:1:deleted', '4:1:changed', '5:1:deleted'])
        ->and(TraceStream::query()->where('model_type', DbProduct::class)->firstOrFail()->version)->toBe(5);
});

it('does not log a model write twice', function (): void {
    installTriggers();

    $product = inTx(fn (): DbProduct => DbProduct::query()->create(['name' => 'Once', 'code' => 'ONE']));
    inTx(fn () => $product->delete());

    expect(log_of())->toBe(['1:1:changed', '2:1:deleted']);
});

it('leaves the listener in charge of models captured by events', function (): void {
    makeProduct();

    expect(log_of())->toBe(['1:1:changed'])
        ->and(TraceStream::query()->firstOrFail()->model_type)->toBe(Product::class);
});

it('rolls the change log back with the write', function (): void {
    installTriggers();

    try {
        inTx(function (): void {
            DB::table('db_products')->insert(['name' => 'Gone']);

            throw new \RuntimeException('rollback');
        });
    } catch (\RuntimeException) {
    }

    expect(log_of())->toBe([])->and(TraceStream::query()->where('model_type', DbProduct::class)->firstOrFail()->version)->toBe(0);
});

it('fails the write loudly when the stream row is missing', function (): void {
    installTriggers();
    TraceStream::query()->delete();

    expect(fn () => DB::table('db_products')->insert(['name' => 'Lost']))->toThrow(\Exception::class)
        ->and(DB::table('db_products')->count())->toBe(0);
});

it('serves what a trigger logged through the feed', function (): void {
    installTriggers();
    DB::table('db_products')->insert(['name' => 'Raw', 'code' => 'RAW-1', 'internal_note' => 'secret']);

    $response = $this->getJson('/api/db-products/changes?since=0', ['Authorization' => 'Bearer '.TestCase::TOKEN])->assertOk();

    expect($response->json('data.0.operation'))->toBe('upsert')
        ->and($response->json('data.0.attributes'))->toBe(['code' => 'RAW-1', 'name' => 'Raw', 'is_active' => true]);
});

it('reports missing, installed and outdated triggers', function (): void {
    $manager = app(TriggerManager::class);
    $definition = $manager->definitions()[0];

    expect($manager->status($definition))->toBe(TriggerStatus::Missing);

    $this->artisan('trace:triggers')->expectsOutputToContain('missing')->assertSuccessful();

    installTriggers();
    expect($manager->status($definition))->toBe(TriggerStatus::Installed);

    TraceMeta::query()->whereKey($definition->metaKey())->update(['value' => 'stale']);
    expect($manager->status($definition))->toBe(TriggerStatus::Outdated);

    installTriggers();
    expect($manager->status($definition))->toBe(TriggerStatus::Installed);
});

it('removes the triggers', function (): void {
    installTriggers();
    $this->artisan('trace:triggers', ['action' => 'remove'])->assertSuccessful();

    DB::table('db_products')->insert(['name' => 'Unseen']);

    expect(log_of())->toBe([])
        ->and(app(TriggerManager::class)->status(app(TriggerManager::class)->definitions()[0]))->toBe(TriggerStatus::Missing)
        ->and(TraceMeta::query()->count())->toBe(0);
});

it('prints the statements without running them', function (): void {
    $this->artisan('trace:triggers', ['action' => 'install', '--sql' => true])
        ->expectsOutputToContain('CREATE TRIGGER')
        ->assertSuccessful();

    expect(app(TriggerManager::class)->status(app(TriggerManager::class)->definitions()[0]))->toBe(TriggerStatus::Missing);
});

it('rejects an unknown action', function (): void {
    $this->artisan('trace:triggers', ['action' => 'explode'])->assertFailed();
});

it('makes the doctor ask for missing triggers', function (): void {
    $this->artisan('trace:doctor', ['--offline' => true])->expectsOutputToContain('run php artisan trace:triggers install')->assertFailed();
});

it('passes the doctor once the triggers are installed', function (): void {
    installTriggers();

    $this->artisan('trace:doctor', ['--offline' => true])->expectsOutputToContain('database triggers')->assertSuccessful();
});

it('makes the doctor flag a trigger left behind a model captured by events', function (): void {
    $definition = app(TriggerManager::class)->definitionFor(Product::class);
    TraceMeta::query()->create(['key' => $definition->metaKey(), 'value' => 'x']);

    $this->artisan('trace:doctor', ['--offline' => true])->expectsOutputToContain('trace:triggers remove')->assertFailed();
});
