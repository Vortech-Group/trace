<?php

declare(strict_types=1);

use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Exceptions\AmbiguousMatchException;
use Vortech\Trace\Models\TraceLink;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Tests\Support\Clinic;
use Vortech\Trace\Tests\Support\RemoteClinic;

it('creates a record and sets the match column create-only when nothing matches', function (): void {
    makeClinic(['code' => 'CL-7']);

    $result = syncClinics();

    expect($result->created)->toBe(1)
        ->and(RemoteClinic::query()->firstOrFail()->clinic_code)->toBe('CL-7');
});

it('links the one existing record that matches instead of duplicating it', function (): void {
    RemoteClinic::query()->create(['clinic_code' => 'CL-7', 'name' => 'Old name', 'local_note' => 'keep me']);
    makeClinic(['code' => 'CL-7', 'name' => 'New name']);

    $result = syncClinics();
    $local = RemoteClinic::query()->firstOrFail();

    expect($result->created)->toBe(0)
        ->and($result->updated)->toBe(1)
        ->and(RemoteClinic::query()->count())->toBe(1)
        ->and($local->name)->toBe('New name')
        ->and($local->local_note)->toBe('keep me')
        ->and(TraceLink::query()->firstOrFail()->model_key)->toBe((string) $local->id);
});

it('stops with a clear error on an ambiguous match and picks nothing', function (): void {
    RemoteClinic::query()->create(['clinic_code' => 'CL-7', 'name' => 'A']);
    RemoteClinic::query()->create(['clinic_code' => 'CL-7', 'name' => 'B']);
    makeClinic(['code' => 'CL-7', 'name' => 'Source']);

    expect(fn () => syncClinics())->toThrow(AmbiguousMatchException::class);

    expect(RemoteClinic::query()->pluck('name')->sort()->values()->all())->toBe(['A', 'B'])
        ->and(TraceLink::query()->count())->toBe(0)
        ->and(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::Failed)
        ->and(TraceSyncState::query()->firstOrFail()->last_error)->toContain('ambiguous');
});

it('does not match on a null source value', function (): void {
    RemoteClinic::query()->create(['clinic_code' => null, 'name' => 'Local without code']);
    makeClinic(['code' => null, 'name' => 'Source without code']);

    $result = syncClinics();

    expect($result->created)->toBe(1)->and(RemoteClinic::query()->count())->toBe(2);
});

it('never re-runs the match once a link exists', function (): void {
    makeClinic(['code' => 'CL-1']);
    syncClinics();
    $local = RemoteClinic::query()->firstOrFail();

    // another local record now carries the business key the source still publishes
    RemoteClinic::query()->create(['clinic_code' => 'CL-1', 'name' => 'Impostor']);
    inTx(fn () => Clinic::query()->firstOrFail()->update(['name' => 'Renamed at source']));
    syncClinics();

    expect(TraceLink::query()->firstOrFail()->model_key)->toBe((string) $local->id)
        ->and($local->refresh()->name)->toBe('Renamed at source')
        ->and(RemoteClinic::query()->where('name', 'Impostor')->firstOrFail()->name)->toBe('Impostor');
});

it('restores a matched local record that was soft deleted', function (): void {
    $existing = RemoteClinic::query()->create(['clinic_code' => 'CL-7', 'name' => 'Old']);
    $existing->delete();
    makeClinic(['code' => 'CL-7']);

    $result = syncClinics();

    expect($result->restored)->toBe(1)->and(RemoteClinic::query()->count())->toBe(1);
});
