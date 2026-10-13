<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Vortech\Trace\Data\SyncResult;
use Vortech\Trace\Events\SyncCompleted;
use Vortech\Trace\Events\SyncStarted;
use Vortech\Trace\Exceptions\AmbiguousMatchException;
use Vortech\Trace\Facades\Remote;
use Vortech\Trace\Models\TraceLink;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Tests\Support\NoMatchRemoteProduct;
use Vortech\Trace\Tests\Support\RemoteProduct;

function dryRun(string $model = RemoteProduct::class, bool $reconcile = false): SyncResult
{
    $builder = Remote::for($model);

    return ($reconcile ? $builder->reconcile() : $builder->sync())->dryRun()->run();
}

function nothingStored(): void
{
    expect(TraceSyncState::query()->count())->toBe(0)
        ->and(TraceLink::query()->count())->toBe(0)
        ->and(TraceSyncRun::query()->count())->toBe(0);
}

it('tells what a first sync would create and writes nothing', function (): void {
    makeProduct();
    makeProduct();
    makeProduct();

    $result = dryRun();

    expect($result->dryRun)->toBeTrue()
        ->and($result->mode->value)->toBe('bootstrap')
        ->and($result->created)->toBe(3)
        ->and($result->matched)->toBe(0)
        ->and(RemoteProduct::query()->count())->toBe(0);

    nothingStored();
});

it('counts the existing local rows it would match and leaves them alone', function (): void {
    $first = makeProduct(['code' => 'PR-A', 'name' => 'Source A']);
    makeProduct(['code' => 'PR-B', 'name' => 'Source B']);
    makeProduct(['code' => 'PR-C', 'name' => 'Source C']);

    RemoteProduct::query()->create(['product_code' => 'PR-A', 'name' => 'Old A', 'local_note' => 'mine']);
    RemoteProduct::query()->create(['product_code' => 'PR-B', 'name' => 'Old B']);
    RemoteProduct::query()->create(['product_code' => 'ONLY-LOCAL', 'name' => 'Not at the source']);

    $result = dryRun();

    expect($result->created)->toBe(1)
        ->and($result->matched)->toBe(2)
        ->and($result->updated)->toBe(2)
        ->and(RemoteProduct::query()->count())->toBe(3)
        ->and(RemoteProduct::query()->where('product_code', 'PR-A')->value('name'))->toBe('Old A')
        ->and($first->exists)->toBeTrue();

    nothingStored();

    // the real run does what the rehearsal announced
    $real = syncProducts();

    expect([$real->created, $real->matched, $real->updated])->toBe([$result->created, $result->matched, $result->updated])
        ->and(RemoteProduct::query()->where('product_code', 'PR-A')->value('name'))->toBe('Source A')
        ->and(RemoteProduct::query()->where('product_code', 'PR-A')->value('local_note'))->toBe('mine')
        ->and(RemoteProduct::query()->count())->toBe(4);
});

it('reports an ambiguous match without changing anything', function (): void {
    makeProduct(['code' => 'PR-A']);
    RemoteProduct::query()->create(['product_code' => 'PR-A', 'name' => 'One']);
    RemoteProduct::query()->create(['product_code' => 'PR-A', 'name' => 'Two']);

    expect(fn () => dryRun())->toThrow(AmbiguousMatchException::class);

    nothingStored();
    expect(RemoteProduct::query()->count())->toBe(2);
});

it('does not move the state: the real sync still finds the changes', function (): void {
    makeProduct();
    syncProducts();
    $cursor = TraceSyncState::query()->firstOrFail()->cursor;

    makeProduct();

    $result = dryRun();

    expect($result->mode->value)->toBe('incremental')
        ->and($result->created)->toBe(1)
        ->and(RemoteProduct::query()->count())->toBe(1)
        ->and(TraceSyncState::query()->firstOrFail()->cursor)->toBe($cursor)
        ->and(TraceSyncRun::query()->count())->toBe(1);

    expect(syncProducts()->created)->toBe(1)->and(RemoteProduct::query()->count())->toBe(2);
});

it('shows what a reconcile would delete, and deletes nothing', function (): void {
    $gone = makeProduct();
    makeProduct();
    syncProducts();

    inTx(fn () => $gone->delete());

    $result = dryRun(reconcile: true);

    expect($result->mode->value)->toBe('reconcile')
        ->and($result->deleted)->toBe(1)
        ->and(RemoteProduct::withTrashed()->whereNotNull('deleted_at')->count())->toBe(0);
});

it('fires neither model events nor sync events', function (): void {
    makeProduct();
    $created = 0;
    RemoteProduct::created(function () use (&$created): void {
        $created++;
    });
    Event::fake([SyncStarted::class, SyncCompleted::class]);

    dryRun();

    expect($created)->toBe(0);
    Event::assertNotDispatched(SyncStarted::class);
    Event::assertNotDispatched(SyncCompleted::class);
});

it('cannot be queued', function (): void {
    expect(fn () => Remote::for(RemoteProduct::class)->sync()->dryRun()->dispatch())->toThrow(\LogicException::class);
});

it('is a dry run of the command', function (): void {
    makeProduct();

    $this->artisan('trace:sync', ['model' => RemoteProduct::class, '--dry-run' => true])
        ->expectsOutputToContain('nothing was written')
        ->expectsOutputToContain('Matched')
        ->assertSuccessful();

    expect(RemoteProduct::query()->count())->toBe(0);
    nothingStored();
});

it('refuses to queue a dry run from the command', function (): void {
    $this->artisan('trace:sync', ['model' => RemoteProduct::class, '--dry-run' => true, '--queue' => true])
        ->expectsOutputToContain('cannot be queued')
        ->assertFailed();
});

it('warns when the mapper cannot match existing rows', function (): void {
    makeProduct();

    $this->artisan('trace:sync', ['model' => NoMatchRemoteProduct::class, '--dry-run' => true])
        ->expectsOutputToContain('declares no match()')
        ->assertSuccessful();
});

it('is a dry run of the dashboard', function (): void {
    Gate::define('viewTrace', fn (?object $user = null): bool => true);
    Gate::define('manageTrace', fn (?object $user = null): bool => true);
    config(['trace.remote.models' => [RemoteProduct::class]]);
    $this->withoutMiddleware(PreventRequestForgery::class);
    makeProduct();
    makeProduct();

    $this->postJson('/trace/api/remote/sync', ['model' => RemoteProduct::class, 'dry' => true])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'Dry run of a bootstrap, nothing was written: 2 new, 0 matched'));

    expect(RemoteProduct::query()->count())->toBe(0);
    nothingStored();
});
