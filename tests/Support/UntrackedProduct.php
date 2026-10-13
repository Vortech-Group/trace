<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/** Same table as Product, but without #[Traceable]. */
final class UntrackedProduct extends Model
{
    protected $table = 'source_products';

    protected $guarded = [];
}
