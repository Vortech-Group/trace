<?php

declare(strict_types=1);

namespace Vortech\Trace\Tests\Support;

use Vortech\Trace\Mapper;

final class ProductMapper extends Mapper
{
    public function fields(): array
    {
        return [
            'name',
            'description',
            'slug',
            'brand',
            'category',
            'barcode' => 'ean',
            'is_active' => 'active',
        ];
    }

    public function match(): array
    {
        return ['code' => 'product_code'];
    }

    public function transform(array $mapped): array
    {
        if (array_key_exists('slug', $mapped) && is_string($mapped['slug'])) {
            $mapped['slug'] = mb_strtolower(trim($mapped['slug']));
        }

        return $mapped;
    }
}
