<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Vortech\Trace\Source;

final class DbProductSource extends Source
{
    protected string $model = DbProduct::class;

    protected array $fields = ['code', 'name', 'is_active'];
}
