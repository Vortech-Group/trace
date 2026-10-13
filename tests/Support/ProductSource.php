<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Vortech\Trace\Source;

final class ProductSource extends Source
{
    protected string $model = Product::class;

    protected array $fields = ['code', 'name', 'description', 'barcode', 'slug', 'brand', 'category', 'is_active'];
}
