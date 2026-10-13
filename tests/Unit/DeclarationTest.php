<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vortech\Trace\Attributes\Sync;
use Vortech\Trace\Attributes\Traceable;
use Vortech\Trace\Exceptions\InvalidMappingException;
use Vortech\Trace\Exceptions\InvalidSyncDefinitionException;
use Vortech\Trace\Mapper;
use Vortech\Trace\Remote\MapperResolver;
use Vortech\Trace\Tests\Support\Product;
use Vortech\Trace\Tests\Support\ProductMapper;
use Vortech\Trace\Tests\Support\RemoteProduct;
use Vortech\Trace\Tests\Support\UntrackedProduct;
use Vortech\Trace\Tracking\TraceableRegistry;

#[Traceable]
class TraceableParent extends Model {}

final class TraceableChild extends \TraceableParent {}

final class PlainModel extends Model {}

final class NoSoftDeletesRemote extends Model {}

final class KeyWritingMapper extends Mapper
{
    public function fields(): array
    {
        return ['id', 'name'];
    }
}

final class CollidingMapper extends Mapper
{
    public function fields(): array
    {
        return ['name', 'title' => 'name'];
    }
}

final class EmptyMapper extends Mapper
{
    public function fields(): array
    {
        return [];
    }
}

#[Sync(source: 'x', mapper: ProductMapper::class)]
final class NoSoftDeletesSync extends Model {}

#[Sync(source: 'x', mapper: \KeyWritingMapper::class)]
final class KeyWritingSync extends Model
{
    use SoftDeletes;
}

#[Sync(source: 'x', mapper: \CollidingMapper::class)]
final class CollidingSync extends Model
{
    use SoftDeletes;
}

#[Sync(source: 'x', mapper: \EmptyMapper::class)]
final class EmptySync extends Model
{
    use SoftDeletes;
}

#[Sync(source: 'x', mapper: \PlainModel::class)]
final class WrongMapperSync extends Model
{
    use SoftDeletes;
}

it('detects the Traceable marker, also on parents, and ignores unmarked models', function (): void {
    $registry = new TraceableRegistry;

    expect($registry->isTraceable(Product::class))->toBeTrue()
        ->and($registry->isTraceable(\TraceableChild::class))->toBeTrue()
        ->and($registry->isTraceable(UntrackedProduct::class))->toBeFalse();
});

it('resolves a valid #[Sync] declaration', function (): void {
    $definition = app(MapperResolver::class)->resolve(RemoteProduct::class);

    expect($definition->feed)->toBe('products')
        ->and($definition->connection)->toBe('default')
        ->and($definition->mapper)->toBeInstanceOf(ProductMapper::class);
});

it('refuses an invalid #[Sync] declaration', function (string $model, string $exception, string $message): void {
    expect(fn () => app(MapperResolver::class)->resolve($model))->toThrow($exception, $message);
})->with([
    'not a model' => [\stdClass::class, InvalidSyncDefinitionException::class, 'not an Eloquent model'],
    'no attribute' => [\NoSoftDeletesRemote::class, InvalidSyncDefinitionException::class, 'no #[Sync]'],
    'no soft deletes' => [\NoSoftDeletesSync::class, InvalidSyncDefinitionException::class, 'SoftDeletes'],
    'mapper is not a mapper' => [\WrongMapperSync::class, InvalidSyncDefinitionException::class, 'must extend'],
    'mapper writes the primary key' => [\KeyWritingSync::class, InvalidMappingException::class, 'managed by Eloquent'],
    'two attributes into one column' => [\CollidingSync::class, InvalidMappingException::class, 'two remote attributes'],
    'empty fields' => [\EmptySync::class, InvalidMappingException::class, 'must not be empty'],
]);

it('skips the soft delete requirement when configured', function (): void {
    config(['trace.safety.require_soft_deletes' => false]);

    expect(app(MapperResolver::class)->resolve(\NoSoftDeletesSync::class)->feed)->toBe('x');
});
