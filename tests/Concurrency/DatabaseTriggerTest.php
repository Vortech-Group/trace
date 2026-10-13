<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vortech\Trace\Capture\TriggerManager;
use Vortech\Trace\Enums\TriggerStatus;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Tests\Support\DbProduct;

/*
 * Runs the generated trigger SQL on a real MySQL or PostgreSQL server, see StreamSequencerTest for the setup.
 */

beforeEach(function (): void {
    $driver = getenv('TRACE_TEST_DB');

    if (! in_array($driver, ['mysql', 'pgsql'], true)) {
        $this->markTestSkipped('Set TRACE_TEST_DB=mysql|pgsql to run the real database trigger tests.');
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

    Schema::connection('a')->dropIfExists('db_products');
    Schema::connection('a')->create('db_products', function (Blueprint $table): void {
        $table->id();
        $table->string('code')->nullable();
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->string('internal_note')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    $this->manager = app(TriggerManager::class);
    $this->definition = $this->manager->definitionFor(DbProduct::class);
    $this->manager->install($this->definition);
});

afterEach(function (): void {
    if (isset($this->manager, $this->definition)) {
        $this->manager->remove($this->definition);
        Schema::connection('a')->dropIfExists('db_products');
    }
});

/** Zero rows while another transaction holds the stream row, one row afterwards. */
function streamLockedByAnother(Connection $connection): bool
{
    return $connection->select('select id from trace_streams where model_type = ? for update skip locked', [DbProduct::class]) === [];
}

it('installs and reports the triggers', function (): void {
    expect($this->manager->status($this->definition))->toBe(TriggerStatus::Installed);

    // installing again replaces them
    $this->manager->install($this->definition);
    expect($this->manager->status($this->definition))->toBe(TriggerStatus::Installed);

    $this->manager->remove($this->definition);
    expect($this->manager->status($this->definition))->toBe(TriggerStatus::Missing);
    $this->manager->install($this->definition);
});

it('logs inserts, updates, soft deletes, restores and deletes written without a model', function (): void {
    $db = DB::connection('a');

    $db->table('db_products')->insert(['name' => 'Raw', 'code' => 'RAW-1']);
    $db->table('db_products')->where('code', 'RAW-1')->update(['name' => 'Renamed']);
    $db->table('db_products')->where('code', 'RAW-1')->update(['deleted_at' => now()]);
    $db->table('db_products')->where('code', 'RAW-1')->update(['deleted_at' => null]);
    $db->table('db_products')->where('code', 'RAW-1')->delete();

    expect(TraceChange::query()->orderBy('version')->get()->map(fn (TraceChange $c): string => "{$c->version}:{$c->model_key}:{$c->operation->value}")->all())
        ->toBe(['1:1:changed', '2:1:changed', '3:1:deleted', '4:1:changed', '5:1:deleted'])
        ->and(TraceStream::query()->where('model_type', DbProduct::class)->firstOrFail()->version)->toBe(5);
});

it('keeps the model lastInsertId intact', function (): void {
    $product = DbProduct::query()->create(['name' => 'Eloquent', 'code' => 'E-1']);

    expect($product->getKey())->toBe(1)->and(TraceChange::query()->count())->toBe(1);
});

it('holds the stream row until the writing transaction ends', function (): void {
    $a = DB::connection('a');
    $b = DB::connection('b');

    $a->beginTransaction();
    $a->table('db_products')->insert(['name' => 'First']);

    $b->beginTransaction();
    expect(streamLockedByAnother($b))->toBeTrue('a concurrent writer must queue behind the open transaction');
    $b->rollBack();

    $a->commit();

    $b->beginTransaction();
    expect(streamLockedByAnother($b))->toBeFalse();
    $b->rollBack();

    $a->table('db_products')->insert(['name' => 'Second']);

    expect(TraceChange::query()->orderBy('version')->pluck('version')->all())->toBe([1, 2]);
});

it('leaves no log behind a rolled back write', function (): void {
    $a = DB::connection('a');

    $a->beginTransaction();
    $a->table('db_products')->insert(['name' => 'Gone']);
    $a->rollBack();

    expect(TraceChange::query()->count())->toBe(0)->and(TraceStream::query()->where('model_type', DbProduct::class)->firstOrFail()->version)->toBe(0);
});
