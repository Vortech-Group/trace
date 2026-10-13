<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Vortech\Trace\Source;

final class DbClinicSource extends Source
{
    protected string $model = DbClinic::class;

    protected array $fields = ['code', 'name', 'is_active'];
}
