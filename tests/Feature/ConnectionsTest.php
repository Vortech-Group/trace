<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vortech\Trace\Attributes\Sync;
use Vortech\Trace\Exceptions\InvalidConfigurationException;
use Vortech\Trace\Exceptions\RemoteAuthenticationException;
use Vortech\Trace\Facades\Remote;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Tests\Support\ProductMapper;
use Vortech\Trace\Tests\Support\RemoteProduct;
use Vortech\Trace\Tests\TestCase;

#[Sync(source: 'products', mapper: ProductMapper::class, connection: 'logistics')]
final class LogisticsProduct extends Model
{
    use SoftDeletes;

    protected $table = 'remote_products';

    protected $guarded = [];
}

it('reads url and token of a named source connection', function (): void {
    makeProduct();
    config(['trace.remote.connections.logistics' => ['url' => 'https://source.test/api', 'token' => TestCase::TOKEN]]);

    $result = Remote::for(\LogisticsProduct::class)->sync()->run();

    expect($result->created)->toBe(1)
        ->and(TraceSyncState::query()->firstOrFail()->source_connection)->toBe('logistics');
});

it('authenticates every connection with its own token', function (): void {
    makeProduct();
    config(['trace.remote.connections.logistics' => ['url' => 'https://source.test/api', 'token' => 'wrong']]);

    expect(syncProducts()->created)->toBe(1);
    expect(fn () => Remote::for(\LogisticsProduct::class)->sync()->run())->toThrow(RemoteAuthenticationException::class);
});

it('keeps a separate sync state per connection', function (): void {
    makeProduct();
    config(['trace.remote.connections.logistics' => ['url' => 'https://source.test/api', 'token' => TestCase::TOKEN]]);

    syncProducts();
    Remote::for(\LogisticsProduct::class)->sync()->run();

    expect(TraceSyncState::query()->pluck('source_connection')->sort()->values()->all())->toBe(['default', 'logistics'])
        ->and(TraceSyncState::query()->whereNotNull('cursor')->count())->toBe(2);
});

it('refuses a connection that is not configured', function (): void {
    expect(fn () => Remote::for(\LogisticsProduct::class)->sync()->run())->toThrow(InvalidConfigurationException::class, 'logistics');

    expect(RemoteProduct::query()->count())->toBe(0);
});
