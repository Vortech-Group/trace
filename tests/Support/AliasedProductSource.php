<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Vortech\Trace\Source;

final class AliasedProductSource extends Source
{
    protected string $model = AliasedProduct::class;

    protected array $fields = ['code', 'name'];
}
