<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Vortech\Trace\Source;

final class ClinicSource extends Source
{
    protected string $model = Clinic::class;

    protected array $fields = ['code', 'name', 'address', 'phone', 'email', 'city', 'postal_code', 'is_active'];
}
