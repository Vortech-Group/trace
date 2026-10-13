<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Vortech\Trace\Capture\TriggerManager;
use Vortech\Trace\Facades\Remote;
use Vortech\Trace\Health\HealthChecker;
use Vortech\Trace\Models\TraceLink;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Support\ModelType;
use Vortech\Trace\Tests\Support\AliasedProduct;
use Vortech\Trace\Tests\Support\AliasedRemoteProduct;
use Vortech\Trace\Tests\Support\Product;
use Vortech\Trace\Tests\TestCase;

function makeAliasedProduct(string $code): AliasedProduct
{
    return DB::transaction(fn (): AliasedProduct => AliasedProduct::query()->create(['code' => $code, 'name' => "Product {$code}"]));
}

it('stores the alias of the model instead of its class name', function (): void {
    makeAliasedProduct('A-1');

    expect(TraceStream::query()->where('model_type', 'aliased-product')->exists())->toBeTrue()
        ->and(TraceStream::query()->where('model_type', AliasedProduct::class)->exists())->toBeFalse();
});

it('serves the feed of an aliased model', function (): void {
    makeAliasedProduct('A-1');
    makeAliasedProduct('A-2');

    $snapshot = $this->withToken(TestCase::TOKEN)->getJson('/api/aliased-products/snapshot')->assertOk();
    expect($snapshot->json('data'))->toHaveCount(2);
});

it('stores the alias of the remote model in the state, the links and the runs', function (): void {
    makeAliasedProduct('A-1');

    Remote::for(AliasedRemoteProduct::class)->sync()->run();

    expect(AliasedRemoteProduct::query()->count())->toBe(1)
        ->and(TraceSyncState::query()->pluck('model_type')->all())->toBe(['remote-aliased-product'])
        ->and(TraceLink::query()->pluck('model_type')->unique()->all())->toBe(['remote-aliased-product'])
        ->and(TraceSyncRun::query()->pluck('model_type')->unique()->all())->toBe(['remote-aliased-product']);

    // a second run finds the state again through the alias
    makeAliasedProduct('A-2');
    Remote::for(AliasedRemoteProduct::class)->sync()->run();

    expect(AliasedRemoteProduct::query()->count())->toBe(2)->and(TraceSyncState::query()->count())->toBe(1);
});

it('shows an aliased remote model on the dashboard', function (): void {
    Gate::define('viewTrace', fn (?object $user = null): bool => true);
    config(['trace.remote.models' => [AliasedRemoteProduct::class]]);
    makeAliasedProduct('A-1');
    Remote::for(AliasedRemoteProduct::class)->sync()->run();

    $this->getJson('/trace/api/remote')->assertOk()->assertJsonPath('models.0.name', 'AliasedRemoteProduct')->assertJsonPath('models.0.status', 'In sync');
    $this->getJson('/trace/api/runs')->assertOk()->assertJsonPath('rows.0.model', 'AliasedRemoteProduct')->assertJsonPath('models', ['remote-aliased-product' => 'AliasedRemoteProduct']);
});

it('hands the alias to the trigger of a model', function (): void {
    expect(app(TriggerManager::class)->definitionFor(AliasedProduct::class)->modelType)->toBe('aliased-product');
});

it('falls back to the morph class when no alias is declared', function (): void {
    expect(ModelType::of(new Product))->toBe(Product::class);

    Relation::morphMap(['product' => Product::class]);

    try {
        expect(ModelType::of(new Product))->toBe('product');
    } finally {
        Relation::morphMap([], merge: false);
    }
});

it('finds the class of a stored name', function (): void {
    config(['trace.remote.models' => [AliasedRemoteProduct::class]]);

    expect(ModelType::classFor('remote-aliased-product'))->toBe(AliasedRemoteProduct::class)
        ->and(ModelType::classFor(Product::class))->toBe(Product::class)
        ->and(ModelType::classFor('unknown'))->toBeNull();
});

it('lists a model once although the config and the stored alias both name it', function (): void {
    Gate::define('viewTrace', fn (?object $user = null): bool => true);
    config(['trace.remote.models' => [AliasedRemoteProduct::class]]);
    makeAliasedProduct('A-1');
    Remote::for(AliasedRemoteProduct::class)->sync()->run();

    expect(app(HealthChecker::class)->remoteModels())->toBe([AliasedRemoteProduct::class]);
    $this->getJson('/trace/api/remote')->assertJsonCount(1, 'models');
});
