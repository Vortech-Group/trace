<?php

declare(strict_types=1);

use Vortech\Trace\Exceptions\InvalidSourceException;
use Vortech\Trace\Source;
use Vortech\Trace\Tests\Support\Product;
use Vortech\Trace\Tests\Support\ProductSource;
use Vortech\Trace\Tests\Support\UntrackedProduct;

function sourceWith(string $model, array $fields): Source
{
    return new class($model, $fields) extends Source
    {
        public function __construct(string $model, array $fields)
        {
            $this->model = $model;
            $this->fields = $fields;
        }
    };
}

it('accepts a valid source', function (): void {
    (new ProductSource)->validate();

    expect((new ProductSource)->fields())->toContain('code', 'name');
});

it('refuses an invalid source', function (string $model, array $fields, string $message): void {
    expect(fn () => sourceWith($model, $fields)->validate())->toThrow(InvalidSourceException::class, $message);
})->with([
    'missing model' => ['Nope\\Missing', ['name'], 'Eloquent model'],
    'not traceable' => [UntrackedProduct::class, ['name'], '#[Traceable]'],
    'no fields' => [Product::class, [], 'non-empty list'],
    'hidden on the model' => [Product::class, ['name', 'internal_note'], 'hidden'],
    'forbidden by config' => [Product::class, ['name', 'password'], 'forbidden_fields'],
    'forbidden regardless of case' => [Product::class, ['Remember_Token'], 'forbidden_fields'],
    'the primary key' => [Product::class, ['id', 'name'], 'primary key'],
    'duplicates' => [Product::class, ['name', 'name'], 'duplicates'],
]);

it('never puts unlisted attributes into the payload', function (): void {
    $product = makeProduct(['internal_note' => 'secret', 'name' => 'Central']);

    $attributes = (new ProductSource)->attributesFor($product);

    expect(array_keys($attributes))->toBe(['code', 'name', 'description', 'barcode', 'slug', 'brand', 'category', 'is_active'])
        ->and($attributes)->not->toHaveKey('internal_note')
        ->and($attributes)->not->toHaveKey('id')
        ->and($attributes['is_active'])->toBeTrue();
});

it('changes the schema hash with the published fields only', function (): void {
    $a = sourceWith(Product::class, ['name', 'code']);
    $b = sourceWith(Product::class, ['code', 'name']);
    $c = sourceWith(Product::class, ['name']);

    expect($a->schemaHash('products'))->toBe($b->schemaHash('products'))
        ->and($a->schemaHash('products'))->not->toBe($c->schemaHash('products'))
        ->and($a->schemaHash('products'))->toStartWith('sha256:');
});

it('reports fields that are no columns', function (): void {
    expect(sourceWith(Product::class, ['name', 'ghost'])->missingColumns())->toBe(['ghost']);
});
