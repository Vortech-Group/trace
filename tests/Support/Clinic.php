<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vortech\Trace\Attributes\Traceable;

#[Traceable(source: ClinicSource::class)]
final class Clinic extends Model
{
    use SoftDeletes;

    protected $table = 'source_clinics';

    protected $guarded = [];

    protected $hidden = ['internal_note'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
