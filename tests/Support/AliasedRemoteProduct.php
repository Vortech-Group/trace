<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vortech\Trace\Attributes\Sync;

#[Sync(source: 'aliased-products', mapper: AliasedProductMapper::class, alias: 'remote-aliased-product')]
final class AliasedRemoteProduct extends Model
{
    use SoftDeletes;

    protected $table = 'aliased_remote_products';

    protected $guarded = [];
}
