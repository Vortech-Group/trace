<?php

declare(strict_types=1);

namespace Vortech\Trace\Data;

use Throwable;
use Vortech\Trace\Exceptions\ProtocolException;
use Vortech\Trace\Support\Payload;

/** A validated page of either endpoint. */
final readonly class FeedPage
{
    /**
     * @param  list<ChangeRecord>  $records
     */
    public function __construct(
        public array $records,
        public string $instanceId,
        public string $schemaHash,
        public ?string $watermark,
        public ?string $nextCursor,
        public bool $hasMore,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ProtocolException
     * @throws Throwable
     */
    public static function fromApi(array $payload, string $feed): self
    {
        $meta = Payload::map($payload['meta'] ?? null, 'meta');

        throw_unless(
            condition: ($meta['protocol'] ?? null) === 1,
            exception: new ProtocolException('The source speaks protocol ['.Payload::string($meta['protocol'] ?? 'unknown', 'protocol').'], this client supports protocol [1].', 'unsupported_protocol'),
        );

        throw_unless(
            condition: ($meta['feed'] ?? null) === $feed,
            exception: new ProtocolException("The response belongs to another feed than [{$feed}].", 'feed_mismatch'),
        );

        $instanceId = Payload::string($meta['instance_id'] ?? null, 'instance_id');
        $schemaHash = Payload::string($meta['schema_hash'] ?? null, 'schema_hash');

        throw_if(
            condition: blank($instanceId) || blank($schemaHash),
            exception: new ProtocolException('The response has no instance_id or schema_hash.', 'invalid_payload'),
        );

        $records = [];

        foreach (Payload::list($payload['data'] ?? null, 'data') as $item) {
            $records[] = ChangeRecord::fromApi(Payload::map($item, 'a feed entry'));
        }

        $nextCursor = Payload::stringOrNull($meta['next_cursor'] ?? null, 'next_cursor');

        throw_if(
            condition: ($meta['has_more'] ?? false) === true && blank($nextCursor),
            exception: new ProtocolException('The response has more pages but no next_cursor.', 'invalid_payload'),
        );

        return new self(
            records: $records,
            instanceId: $instanceId,
            schemaHash: $schemaHash,
            watermark: Payload::stringOrNull($meta['watermark'] ?? null, 'watermark'),
            nextCursor: filled($nextCursor) ? $nextCursor : null,
            hasMore: ($meta['has_more'] ?? false) === true,
        );
    }
}
