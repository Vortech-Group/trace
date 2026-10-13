<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Vortech\Trace\Mapper;

final class NoMatchMapper extends Mapper
{
    public function fields(): array
    {
        return ['name'];
    }
}
