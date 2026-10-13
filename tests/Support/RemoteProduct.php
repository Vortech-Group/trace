<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vortech\Trace\Attributes\Sync;

#[Sync(source: 'products', mapper: ProductMapper::class)]
final class RemoteProduct extends Model
{
    use SoftDeletes;

    protected $table = 'remote_products';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
