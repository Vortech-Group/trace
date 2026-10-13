<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vortech\Trace\Data\SyncResult;
use Vortech\Trace\Facades\Remote;
use Vortech\Trace\Tests\Support\Clinic;
use Vortech\Trace\Tests\Support\RemoteClinic;
use Vortech\Trace\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Concurrency');

pest()->beforeEach(function (): void {
    $this->bridgeSource();
})->in('Feature');

/** Source writes happen in a transaction: the supported, lossless write path. */
function inTx(\Closure $callback): mixed
{
    return DB::transaction($callback);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeClinic(array $attributes = []): Clinic
{
    static $n = 0;
    $n++;

    return inTx(fn (): Clinic => Clinic::query()->create($attributes + [
        'code' => sprintf('CL-%04d', $n),
        'name' => "Clinic {$n}",
        'email' => "Clinic{$n}@Example.com ",
        'is_active' => true,
        'internal_note' => 'never published',
    ]));
}

function syncClinics(): SyncResult
{
    return Remote::for(RemoteClinic::class)->sync()->run();
}

function reconcileClinics(): SyncResult
{
    return Remote::for(RemoteClinic::class)->reconcile()->run();
}
