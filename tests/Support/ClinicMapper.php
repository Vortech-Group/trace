<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Vortech\Trace\Mapper;

final class ClinicMapper extends Mapper
{
    public function fields(): array
    {
        return [
            'name',
            'address',
            'email',
            'city',
            'postal_code',
            'phone' => 'phone_number',
            'is_active' => 'active',
        ];
    }

    public function match(): array
    {
        return ['code' => 'clinic_code'];
    }

    public function transform(array $mapped): array
    {
        if (array_key_exists('email', $mapped) && is_string($mapped['email'])) {
            $mapped['email'] = mb_strtolower(trim($mapped['email']));
        }

        return $mapped;
    }
}
