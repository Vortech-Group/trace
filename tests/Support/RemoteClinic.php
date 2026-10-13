<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vortech\Trace\Attributes\Sync;

#[Sync(source: 'clinics', mapper: ClinicMapper::class)]
final class RemoteClinic extends Model
{
    use SoftDeletes;

    protected $table = 'remote_clinics';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
