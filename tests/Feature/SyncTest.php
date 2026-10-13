<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vortech\Trace\Enums\RunStatus;
use Vortech\Trace\Enums\SyncMode;
use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Events\SyncCompleted;
use Vortech\Trace\Events\SyncStarted;
use Vortech\Trace\Facades\Remote;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceLink;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Tests\Support\Clinic;
use Vortech\Trace\Tests\Support\RemoteClinic;

/** @return list<string> every UPDATE statement that touched the remote clinics table */
function countRemoteUpdates(\Closure $callback): int
{
    $updates = 0;
    DB::listen(function ($query) use (&$updates): void {
        if (str_starts_with(strtolower($query->sql), 'update "remote_clinics"')) {
            $updates++;
        }
    });

    $callback();

    return $updates;
}

it('bootstraps 1000 source records into exactly 1000 links', function (): void {
    config(['trace.source.page_size' => 500]);
    inTx(function (): void {
        foreach (range(1, 1000) as $_) {
            makeClinic();
        }
    });

    $result = syncClinics();

    expect($result->mode)->toBe(SyncMode::Bootstrap)
        ->and($result->created)->toBe(1000)
        ->and(RemoteClinic::query()->count())->toBe(1000)
        ->and(TraceLink::query()->count())->toBe(1000)
        ->and(TraceLink::query()->distinct()->count('remote_key'))->toBe(1000)
        ->and($result->cursor)->toBeString()
        ->and(TraceSyncState::query()->firstOrFail()->status)->toBe(SyncStatus::Ready)
        ->and(TraceSyncState::query()->firstOrFail()->bootstrap_watermark)->toBeNull();
});

it('does not duplicate rows or issue needless updates when run again', function (): void {
    foreach (range(1, 5) as $_) {
        makeClinic();
    }
    syncClinics();

    $updates = countRemoteUpdates(function (): void {
        $second = syncClinics();
        expect($second->mode)->toBe(SyncMode::Incremental)
            ->and($second->created + $second->updated + $second->deleted)->toBe(0);
    });

    expect($updates)->toBe(0)
        ->and(RemoteClinic::query()->count())->toBe(5)
        ->and(TraceLink::query()->count())->toBe(5);
});

it('touches only the record that changed in an incremental run', function (): void {
    makeClinic(['name' => 'One']);
    $two = makeClinic(['name' => 'Two']);
    makeClinic(['name' => 'Three']);
    syncClinics();
    $this->requests = [];

    inTx(fn () => $two->update(['name' => 'Two (new)']));
    $updates = countRemoteUpdates(function (): void {
        $result = syncClinics();
        expect($result->updated)->toBe(1)->and($result->created)->toBe(0)->and($result->unchanged)->toBe(0);
    });

    expect($updates)->toBe(1)
        ->and(RemoteClinic::query()->where('name', 'Two (new)')->count())->toBe(1)
        ->and(collect($this->requests)->every(fn (string $uri): bool => str_contains($uri, '/changes')))->toBeTrue();
});

it('writes renamed attributes to the mapped column and leaves other local columns alone', function (): void {
    makeClinic(['code' => 'CL-1', 'phone' => '+361', 'is_active' => true, 'email' => ' Info@Example.COM ']);
    syncClinics();
    $local = RemoteClinic::query()->firstOrFail();
    $local->forceFill(['local_note' => 'mine'])->save();

    inTx(fn () => Clinic::query()->firstOrFail()->update(['phone' => '+362', 'is_active' => false]));
    syncClinics();

    $local->refresh();
    expect($local->phone_number)->toBe('+362')
        ->and($local->active)->toBeFalse()
        ->and($local->local_note)->toBe('mine')
        ->and($local->email)->toBe('info@example.com', 'transform() lower-cased and trimmed it');
});

it('keeps a local business key that is not mapped', function (): void {
    makeClinic(['code' => 'CL-1']);
    syncClinics();

    inTx(fn () => Clinic::query()->firstOrFail()->update(['code' => 'CL-RENAMED', 'name' => 'Changed']));
    syncClinics();

    expect(RemoteClinic::query()->count())->toBe(1)
        ->and(RemoteClinic::query()->firstOrFail()->clinic_code)->toBe('CL-1')
        ->and(RemoteClinic::query()->firstOrFail()->name)->toBe('Changed');
});

it('soft deletes locally when the source soft deletes, and restores when it restores', function (): void {
    $clinic = makeClinic();
    syncClinics();

    inTx(fn () => $clinic->delete());
    $deleted = syncClinics();

    expect($deleted->deleted)->toBe(1)
        ->and(RemoteClinic::query()->count())->toBe(0)
        ->and(RemoteClinic::withTrashed()->count())->toBe(1);

    inTx(fn () => $clinic->restore());
    $restored = syncClinics();

    expect($restored->restored)->toBe(1)
        ->and(RemoteClinic::query()->count())->toBe(1)
        ->and(TraceLink::query()->count())->toBe(1);
});

it('turns a source force delete into a tombstone and a local soft delete', function (): void {
    $clinic = makeClinic();
    syncClinics();

    inTx(fn () => $clinic->forceDelete());
    $result = syncClinics();

    expect($result->deleted)->toBe(1)
        ->and(RemoteClinic::query()->count())->toBe(0)
        ->and(RemoteClinic::onlyTrashed()->count())->toBe(1)
        ->and(TraceChange::query()->where('operation', 'deleted')->count())->toBe(1);
});

it('never hard deletes anything locally', function (): void {
    $clinic = makeClinic();
    syncClinics();
    inTx(fn () => $clinic->forceDelete());
    syncClinics();

    expect(DB::table('remote_clinics')->count())->toBe(1);
});

it('ignores a tombstone for a key it never linked', function (): void {
    $clinic = makeClinic();
    inTx(fn () => $clinic->forceDelete());
    RemoteClinic::query()->create(['clinic_code' => 'LOCAL', 'name' => 'Local only']);

    $result = syncClinics();

    expect($result->deleted)->toBe(0)->and(RemoteClinic::query()->count())->toBe(1);
});

it('replays the changes made while a long bootstrap was running', function (): void {
    config(['trace.source.page_size' => 2]);
    foreach (range(1, 4) as $_) {
        makeClinic();
    }

    $this->interceptor = function ($request, int $n) {
        if ($n === 2) {
            // between page 1 and page 2: an imported record changes, another vanishes, a new one appears
            inTx(fn () => Clinic::query()->findOrFail(1)->update(['name' => 'Changed meanwhile']));
            inTx(fn () => Clinic::query()->findOrFail(2)->delete());
            makeClinic(['name' => 'Born meanwhile']);
        }

        return null;
    };

    syncClinics();

    $remote = RemoteClinic::query()->orderBy('id')->pluck('name')->all();
    $source = Clinic::query()->orderBy('id')->pluck('name')->all();

    expect(collect($remote)->sort()->values()->all())->toBe(collect($source)->sort()->values()->all())
        ->and(RemoteClinic::onlyTrashed()->count())->toBe(1)
        ->and(RemoteClinic::query()->count())->toBe(4);
});

it('records runs, events and counters', function (): void {
    \Event::fake([SyncStarted::class, SyncCompleted::class]);
    makeClinic();

    $result = syncClinics();
    $run = TraceSyncRun::query()->firstOrFail();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->created)->toBe(1)
        ->and($run->mode)->toBe(SyncMode::Bootstrap)
        ->and($run->finished_at)->not->toBeNull()
        ->and($result->runId)->toBe($run->id)
        ->and($result->durationMs)->toBeInt();

    \Event::assertDispatched(SyncStarted::class);
    \Event::assertDispatched(SyncCompleted::class, fn (SyncCompleted $event): bool => $event->result->created === 1);
});

it('building a sync has no side effect', function (): void {
    makeClinic();

    Remote::for(RemoteClinic::class)->sync()->onQueue('integrations');
    Remote::for(RemoteClinic::class)->reconcile();

    expect($this->requests)->toBe([])
        ->and(TraceSyncState::query()->count())->toBe(0)
        ->and(RemoteClinic::query()->count())->toBe(0);
});

it('does not log record values', function (): void {
    \Log::spy();
    makeClinic(['name' => 'Highly Sensitive Name']);
    syncClinics();

    \Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'trace.sync.completed' && ! str_contains((string) json_encode($context), 'Highly Sensitive Name'));
});
