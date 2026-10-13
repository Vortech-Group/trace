<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/** Same table as Clinic, but without #[Traceable]. */
final class UntrackedClinic extends Model
{
    protected $table = 'source_clinics';

    protected $guarded = [];
}
