<?php

declare(strict_types=1);

use Vortech\Trace\Exceptions\InvalidMappingException;
use Vortech\Trace\Exceptions\SchemaMismatchException;
use Vortech\Trace\Mapper;
use Vortech\Trace\Remote\AttributeMapper;
use Vortech\Trace\Tests\Support\ProductMapper;

final class SloppyMapper extends Mapper
{
    public function fields(): array
    {
        return ['name'];
    }

    public function transform(array $mapped): array
    {
        $mapped['is_admin'] = true;

        return $mapped;
    }
}

function productPayload(array $override = []): array
{
    return $override + [
        'name' => 'Central',
        'description' => 'Main st 1',
        'slug' => ' Info-Slug ',
        'brand' => 'Budapest',
        'category' => '1011',
        'barcode' => '+36123',
        'is_active' => true,
        'code' => 'PR-1',
    ];
}

it('keeps equal names and renames the others', function (): void {
    $mapped = (new AttributeMapper)->map(new ProductMapper, productPayload());

    expect($mapped)->toHaveKeys(['name', 'ean', 'active'])
        ->and($mapped)->not->toHaveKeys(['barcode', 'is_active', 'code'])
        ->and($mapped['ean'])->toBe('+36123')
        ->and($mapped['active'])->toBeTrue();
});

it('runs transform() on local column names', function (): void {
    expect((new AttributeMapper)->map(new ProductMapper, productPayload())['slug'])->toBe('info-slug');
});

it('treats a sent null as a value', function (): void {
    $mapped = (new AttributeMapper)->map(new ProductMapper, productPayload(['barcode' => null, 'slug' => null]));

    expect($mapped)->toHaveKey('ean', null)
        ->and($mapped)->toHaveKey('slug', null);
});

it('treats a missing attribute as a schema error, never as null', function (): void {
    $payload = productPayload();
    unset($payload['barcode']);

    expect(fn () => (new AttributeMapper)->map(new ProductMapper, $payload))
        ->toThrow(SchemaMismatchException::class, 'barcode');
});

it('refuses a transform() that adds a column outside the whitelist', function (): void {
    expect(fn () => (new AttributeMapper)->map(new \SloppyMapper, ['name' => 'x']))
        ->toThrow(InvalidMappingException::class, 'is_admin');
});

it('builds the match values from the remote attributes and skips nulls', function (): void {
    $mapper = new AttributeMapper;

    expect($mapper->matchValues(new ProductMapper, productPayload()))->toBe(['product_code' => 'PR-1'])
        ->and($mapper->matchValues(new ProductMapper, productPayload(['code' => null])))->toBe([])
        ->and(fn () => $mapper->matchValues(new ProductMapper, ['name' => 'x']))->toThrow(SchemaMismatchException::class, 'code');
});

it('exposes the normalised mapping', function (): void {
    expect((new ProductMapper)->mapping())->toMatchArray(['name' => 'name', 'barcode' => 'ean', 'is_active' => 'active'])
        ->and((new ProductMapper)->localColumns())->toContain('ean', 'name');
});
