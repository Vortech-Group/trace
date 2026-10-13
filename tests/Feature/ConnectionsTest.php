<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vortech\Trace\Attributes\Sync;
use Vortech\Trace\Exceptions\InvalidConfigurationException;
use Vortech\Trace\Exceptions\RemoteAuthenticationException;
use Vortech\Trace\Facades\Remote;
use Vortech\Trace\Models\TraceSyncState;
use Vortech\Trace\Tests\Support\ClinicMapper;
use Vortech\Trace\Tests\Support\RemoteClinic;
use Vortech\Trace\Tests\TestCase;

#[Sync(source: 'clinics', mapper: ClinicMapper::class, connection: 'hospital')]
final class HospitalClinic extends Model
{
    use SoftDeletes;

    protected $table = 'remote_clinics';

    protected $guarded = [];
}

it('reads url and token of a named source connection', function (): void {
    makeClinic();
    config(['trace.remote.connections.hospital' => ['url' => 'https://source.test/api', 'token' => TestCase::TOKEN]]);

    $result = Remote::for(\HospitalClinic::class)->sync()->run();

    expect($result->created)->toBe(1)
        ->and(TraceSyncState::query()->firstOrFail()->source_connection)->toBe('hospital');
});

it('authenticates every connection with its own token', function (): void {
    makeClinic();
    config(['trace.remote.connections.hospital' => ['url' => 'https://source.test/api', 'token' => 'wrong']]);

    expect(syncClinics()->created)->toBe(1);
    expect(fn () => Remote::for(\HospitalClinic::class)->sync()->run())->toThrow(RemoteAuthenticationException::class);
});

it('keeps a separate sync state per connection', function (): void {
    makeClinic();
    config(['trace.remote.connections.hospital' => ['url' => 'https://source.test/api', 'token' => TestCase::TOKEN]]);

    syncClinics();
    Remote::for(\HospitalClinic::class)->sync()->run();

    expect(TraceSyncState::query()->pluck('source_connection')->sort()->values()->all())->toBe(['default', 'hospital'])
        ->and(TraceSyncState::query()->whereNotNull('cursor')->count())->toBe(2);
});

it('refuses a connection that is not configured', function (): void {
    expect(fn () => Remote::for(\HospitalClinic::class)->sync()->run())->toThrow(InvalidConfigurationException::class, 'hospital');

    expect(RemoteClinic::query()->count())->toBe(0);
});
