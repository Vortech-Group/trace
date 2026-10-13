<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vortech\Trace\Attributes\Traceable;
use Vortech\Trace\Enums\CaptureMode;

/** The change log of this model is written by database triggers. */
#[Traceable(source: DbClinicSource::class, capture: CaptureMode::Database)]
final class DbClinic extends Model
{
    use SoftDeletes;

    protected $table = 'db_clinics';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
