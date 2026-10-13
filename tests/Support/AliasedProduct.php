<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vortech\Trace\Attributes\Traceable;

#[Traceable(source: AliasedProductSource::class, alias: 'aliased-product')]
final class AliasedProduct extends Model
{
    use SoftDeletes;

    protected $table = 'aliased_products';

    protected $guarded = [];
}
