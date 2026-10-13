<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Vortech\Trace\Mapper;

final class AliasedProductMapper extends Mapper
{
    public function fields(): array
    {
        return ['name'];
    }

    public function match(): array
    {
        return ['code' => 'code'];
    }
}
