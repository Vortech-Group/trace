<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Vortech\Trace\Attributes\Traceable;
use Vortech\Trace\Exceptions\InvalidSourceException;
use Vortech\Trace\Source\SourceRegistry;
use Vortech\Trace\Tests\Support\ProductSource;
use Vortech\Trace\Tests\Support\UntrackedProduct;
use Vortech\Trace\Tests\TestCase;

#[Traceable]
final class TraceableWithoutSource extends Model {}

#[Traceable(source: ProductSource::class)]
final class OtherModelNamingProductSource extends Model {}

it('serves the same feed whether the route names the model or the source', function (): void {
    makeProduct(['code' => 'PR-1']);
    $headers = ['Authorization' => 'Bearer '.TestCase::TOKEN];

    $bySource = $this->getJson('/api/products/snapshot', $headers)->assertOk()->json('data');
    $byModel = $this->getJson('/api/products-by-model/snapshot', $headers)->assertOk()->json('data');

    expect($byModel)->toBe($bySource);
});

it('stores the resolved source, never the model, in the route defaults', function (): void {
    expect(app(SourceRegistry::class)->all())->toMatchArray([
        'products' => ProductSource::class,
        'products-by-model' => ProductSource::class,
    ]);
});

it('publishes one model through several feeds when the routes name a source', function (): void {
    Route::source('products-copy', ProductSource::class);

    expect(app(SourceRegistry::class)->all())->toHaveKeys(['products', 'products-by-model', 'products-copy']);
});

it('refuses a model that does not name its source', function (string $class, string $message): void {
    expect(fn () => Route::source('broken', $class))->toThrow(InvalidSourceException::class, $message);
})->with([
    'no #[Traceable]' => [UntrackedProduct::class, 'does not name its source'],
    'no source in #[Traceable]' => [\TraceableWithoutSource::class, 'does not name its source'],
    'source of another model' => [\OtherModelNamingProductSource::class, 'different model'],
    'neither source nor model' => [\stdClass::class, 'must be a'],
]);
