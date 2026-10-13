<?php

declare(strict_types=1);

namespace Vortech\Trace\Data;

use Throwable;
use Vortech\Trace\Enums\Operation;
use Vortech\Trace\Exceptions\ProtocolException;
use Vortech\Trace\Support\Payload;

/** One entry of a snapshot or changes page. */
final readonly class ChangeRecord
{
    /**
     * @param  array<string, mixed>  $attributes  empty for a delete
     */
    public function __construct(
        public string $key,
        public Operation $operation,
        public array $attributes = [],
        public ?string $version = null,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     *
     * @throws ProtocolException
     * @throws Throwable
     */
    public static function fromApi(array $item): self
    {
        $key = Payload::string($item['key'] ?? null, 'key');

        throw_if(
            condition: blank($key),
            exception: new ProtocolException('A feed entry has an empty key.', 'invalid_payload'),
        );

        $operation = Operation::tryFrom(Payload::string($item['operation'] ?? Operation::Upsert->value, 'operation'));

        throw_if(
            condition: blank($operation),
            exception: new ProtocolException("The feed entry [{$key}] has an unknown operation.", 'invalid_payload'),
        );

        return new self(
            key: $key,
            operation: $operation,
            attributes: $operation->is(Operation::Upsert) ? Payload::map($item['attributes'] ?? null, "attributes of [{$key}]") : [],
            version: Payload::stringOrNull($item['version'] ?? null, 'version'),
        );
    }
}
