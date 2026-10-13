<?php

declare(strict_types=1);

namespace Vortech\Trace\Source;

use Illuminate\Database\Eloquent\Model;
use Throwable;
use Vortech\Trace\Enums\CursorType;
use Vortech\Trace\Exceptions\InvalidCursorException;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Source;
use Vortech\Trace\Support\TraceConfig;

/** One page of the complete feed, in primary key order (keyset pagination, never OFFSET). */
final readonly class SnapshotReader
{
    /**
     * @return array{data: list<array{key: string, attributes: array<string, mixed>}>, meta: array<string, mixed>}
     *
     * @throws InvalidCursorException
     * @throws Throwable
     */
    public function read(Source $source, string $feed, ?string $token, int $limit): array
    {
        $instanceId = InstanceId::get();
        $lastKey = null;

        if (blank($token)) {
            // taken before the first row is read: everything committed after it is replayed by the changes feed
            $watermark = $this->watermark($source);
        } else {
            $cursor = Cursor::decode($token, CursorType::Snapshot, $feed, $instanceId);
            $watermark = $cursor->version;
            $lastKey = $cursor->lastKey;
        }

        $query = $source->query()->orderBy($source->keyName())->limit($limit + 1);

        if (filled($lastKey)) {
            $query->where($source->keyName(), '>', $this->typedKey($source, $lastKey));
        }

        $models = $query->get();
        $hasMore = $models->count() > $limit;
        $page = $models->take($limit)->values();

        $data = [];

        foreach ($page as $model) {
            $data[] = ['key' => $source->keyOf($model), 'attributes' => $source->attributesFor($model)];
        }

        $last = $page->last();

        return [
            'data' => $data,
            'meta' => [
                'protocol' => 1,
                'instance_id' => $instanceId,
                'feed' => $feed,
                'schema_hash' => $source->schemaHash($feed),
                'watermark' => (string) $watermark,
                'next_cursor' => $hasMore && $last instanceof Model
                    ? (new Cursor(CursorType::Snapshot, $feed, $instanceId, $watermark, $source->keyOf($last), now()->getTimestamp() + TraceConfig::snapshotCursorTtl() * 60))->encode()
                    : null,
                'has_more' => $hasMore,
            ],
        ];
    }

    private function watermark(Source $source): int
    {
        $stream = TraceStream::query()->where('model_type', $source->morphClass())->first();

        return $stream instanceof TraceStream ? $stream->version : 0;
    }

    private function typedKey(Source $source, string $key): string|int
    {
        return $source->newModel()->getKeyType() === 'int' ? (int) $key : $key;
    }
}
