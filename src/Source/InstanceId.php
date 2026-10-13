<?php

declare(strict_types=1);

namespace Vortech\Trace\Source;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Vortech\Trace\Models\TraceMeta;

/** The durable identity of this installation, independent of its URL. */
final readonly class InstanceId
{
    private const KEY = 'instance_id';

    public static function get(): string
    {
        $existing = TraceMeta::query()->find(self::KEY);

        if ($existing instanceof TraceMeta) {
            return $existing->value;
        }

        try {
            return TraceMeta::query()->create(['key' => self::KEY, 'value' => (string) Str::uuid()])->value;
        } catch (UniqueConstraintViolationException) {
            return TraceMeta::query()->findOrFail(self::KEY)->value;
        }
    }
}
