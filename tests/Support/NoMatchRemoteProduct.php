<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vortech\Trace\Attributes\Sync;

#[Sync(source: 'products', mapper: NoMatchMapper::class)]
final class NoMatchRemoteProduct extends Model
{
    use SoftDeletes;

    protected $table = 'remote_products';

    protected $guarded = [];
}
