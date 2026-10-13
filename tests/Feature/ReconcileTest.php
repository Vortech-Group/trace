<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Vortech\Trace\Enums\SyncMode;
use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Exceptions\DeletionGuardException;
use Vortech\Trace\Exceptions\RemoteUnavailableException;
use Vortech\Trace\Facades\Remote;
use Vortech\Trace\Models\TraceLink;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Tests\Support\Clinic;
use Vortech\Trace\Tests\Support\RemoteClinic;

it('misses writes without model events until a full reconcile repairs them', function (): void {
    foreach (range(1, 3) as $_) {
        makeClinic();
    }
    syncClinics();

    Clinic::query()->update(['name' => 'Silently renamed']);
    DB::table('source_clinics')->where('id', 3)->delete();
    DB::table('source_clinics')->insert(['code' => 'RAW', 'name' => 'Imported raw', 'is_active' => true]);

    $incremental = syncClinics();
    expect($incremental->updated + $incremental->created + $incremental->deleted)->toBe(0)
        ->and(RemoteClinic::query()->where('name', 'Silently renamed')->count())->toBe(0);

    $reconcile = reconcileClinics();

    expect($reconcile->mode)->toBe(SyncMode::Reconcile)
        ->and($reconcile->updated)->toBe(2)
        ->and($reconcile->created)->toBe(1)
        ->and($reconcile->deleted)->toBe(1)
        ->and(RemoteClinic::query()->where('name', 'Silently renamed')->count())->toBe(2)
        ->and(RemoteClinic::onlyTrashed()->count())->toBe(1)
        ->and(RemoteClinic::query()->where('name', 'Imported raw')->count())->toBe(1);
});

it('keeps local records that Trace does not own, also across a reconcile', function (): void {
    RemoteClinic::query()->create(['clinic_code' => 'LOCAL-ONLY', 'name' => 'Mine']);
    makeClinic();
    syncClinics();
    DB::table('source_clinics')->delete();

    $reconcile = reconcileClinics();

    expect($reconcile->deleted)->toBe(1)
        ->and(RemoteClinic::query()->where('clinic_code', 'LOCAL-ONLY')->count())->toBe(1)
        ->and(RemoteClinic::query()->count())->toBe(1);
});

it('refuses an unusually large vanish and soft deletes nothing', function (): void {
    config(['trace.safety.min_records_for_ratio_guard' => 4, 'trace.safety.max_missing_deletion_ratio' => 0.2]);
    foreach (range(1, 5) as $_) {
        makeClinic();
    }
    syncClinics();
    DB::table('source_clinics')->whereIn('id', [1, 2, 3])->delete();

    expect(fn () => reconcileClinics())->toThrow(DeletionGuardException::class);

    expect(RemoteClinic::query()->count())->toBe(5)
        ->and(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::ReconcileRequired);

    $result = Remote::for(RemoteClinic::class)->reconcile()->allowMassDeletion()->run();

    expect($result->deleted)->toBe(3)
        ->and(RemoteClinic::query()->count())->toBe(2)
        ->and(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::Ready);
});

it('does not delete anything by absence when the traversal fails half way', function (): void {
    config(['trace.source.page_size' => 2]);
    foreach (range(1, 4) as $_) {
        makeClinic();
    }
    syncClinics();
    DB::table('source_clinics')->where('id', 1)->delete();

    $this->requests = [];
    $this->interceptor = fn ($request, int $n) => $n === 2 ? throw new ConnectionException('timeout') : null;

    expect(fn () => reconcileClinics())->toThrow(RemoteUnavailableException::class);

    expect(RemoteClinic::query()->count())->toBe(4)
        ->and(RemoteClinic::onlyTrashed()->count())->toBe(0);
});

it('keeps the links of the previous state when a reconcile fails', function (): void {
    makeClinic();
    syncClinics();
    $cursor = TraceSyncState::query()->firstOrFail()->cursor;

    $this->interceptor = fn () => \Http::response(['error' => 'x'], 500);

    expect(fn () => reconcileClinics())->toThrow(RemoteUnavailableException::class)
        ->and(TraceSyncState::query()->firstOrFail()->cursor)->toBe($cursor)
        ->and(TraceLink::query()->count())->toBe(1);
});
