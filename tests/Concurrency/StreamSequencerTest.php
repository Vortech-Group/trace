<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Tests\Support\Product;
use Vortech\Trace\Tracking\StreamSequencer;

/*
 * These tests need a real MySQL or PostgreSQL server: SQLite ignores row locks. Run them with
 *   TRACE_TEST_DB=mysql|pgsql TRACE_TEST_HOST=... TRACE_TEST_DATABASE=... TRACE_TEST_USERNAME=... TRACE_TEST_PASSWORD=... vendor/bin/pest tests/Concurrency
 * Two connections of one process stand in for two parallel transactions.
 */

beforeEach(function (): void {
    $driver = getenv('TRACE_TEST_DB');

    if (! in_array($driver, ['mysql', 'pgsql'], true)) {
        $this->markTestSkipped('Set TRACE_TEST_DB=mysql|pgsql to run the real database concurrency tests.');
    }

    foreach (['a', 'b'] as $name) {
        config(["database.connections.{$name}" => [
            'driver' => $driver,
            'host' => getenv('TRACE_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('TRACE_TEST_PORT') ?: ($driver === 'mysql' ? '3306' : '5432'),
            'database' => getenv('TRACE_TEST_DATABASE') ?: 'trace_test',
            'username' => getenv('TRACE_TEST_USERNAME') ?: 'root',
            'password' => getenv('TRACE_TEST_PASSWORD') ?: '',
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'prefix' => '',
        ]]);
    }

    config(['database.default' => 'a', 'trace.connection' => 'a']);
    DB::purge('a');
    DB::purge('b');

    $this->artisan('migrate:fresh', ['--database' => 'a', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
});

/** Zero rows while another transaction holds the stream row, one row afterwards. */
function lockedByAnother(Connection $connection): bool
{
    return $connection->select('select id from trace_streams where model_type = ? for update skip locked', [Product::class]) === [];
}

it('serialises version assignment: the second writer cannot take the stream while the first is open', function (): void {
    $a = DB::connection('a');
    $b = DB::connection('b');
    TraceStream::query()->create(['model_type' => Product::class, 'version' => 0]);

    $a->beginTransaction();
    $first = app(StreamSequencer::class)->next(Product::class);
    expect($first['version'])->toBe(1);

    $b->beginTransaction();
    expect(lockedByAnother($b))->toBeTrue('a concurrent transaction must not be able to read-lock the row');
    $b->rollBack();

    $a->commit();

    $b->beginTransaction();
    expect(lockedByAnother($b))->toBeFalse();
    $b->rollBack();

    expect(app(StreamSequencer::class)->next(Product::class)['version'])->toBe(2);
});

it('does not leave a committed change log row for a rolled back write', function (): void {
    $a = DB::connection('a');
    TraceStream::query()->create(['model_type' => Product::class, 'version' => 0]);

    $a->beginTransaction();
    app(StreamSequencer::class)->next(Product::class);
    TraceChange::query()->create(['stream_id' => 1, 'version' => 1, 'model_key' => '1', 'operation' => 'changed']);
    $a->rollBack();

    expect(TraceChange::query()->count())->toBe(0)->and(TraceStream::query()->firstOrFail()->version)->toBe(0);
});

it('assigns versions in commit order for two interleaved transactions', function (): void {
    $a = DB::connection('a');
    $b = DB::connection('b');
    TraceStream::query()->create(['model_type' => Product::class, 'version' => 0]);

    $a->beginTransaction();
    $versionA = app(StreamSequencer::class)->next(Product::class)['version'];

    // B would block here in production; skip-locked proves it cannot overtake A
    $b->beginTransaction();
    expect(lockedByAnother($b))->toBeTrue();
    $b->rollBack();

    $a->commit();
    $versionB = app(StreamSequencer::class)->next(Product::class)['version'];

    expect([$versionA, $versionB])->toBe([1, 2]);
});
