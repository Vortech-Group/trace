<?php

declare(strict_types=1);

namespace Vortech\Trace\Source;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;
use Vortech\Trace\Enums\CursorType;
use Vortech\Trace\Enums\Operation;
use Vortech\Trace\Exceptions\CursorExpiredException;
use Vortech\Trace\Exceptions\InvalidCursorException;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Source;

/**
 * The state-convergence change feed. The log only remembers which keys changed; the
 * payload is the current state of the record, or a tombstone when it is gone.
 */
final readonly class ChangeFeedReader
{
    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     *
     * @throws InvalidCursorException
     * @throws CursorExpiredException
     * @throws Throwable
     */
    public function read(Source $source, string $feed, ?string $token, ?int $since, int $limit): array
    {
        throw_if(
            condition: filled($token) && filled($since),
            exception: new InvalidCursorException('Pass either a cursor or a since version, not both.', 'invalid_cursor'),
        );

        throw_if(
            condition: blank($token) && blank($since),
            exception: new InvalidCursorException('A cursor (or the since version of a snapshot watermark) is required.', 'cursor_required'),
        );

        $instanceId = InstanceId::get();
        $position = filled($token)
            ? Cursor::decode($token, CursorType::Changes, $feed, $instanceId)->version
            : ($since ?? 0);

        $stream = TraceStream::query()->where('model_type', $source->morphClass())->first();
        // read before the log: every version up to it is committed, because versions are handed out under a row lock
        $current = $stream instanceof TraceStream ? $stream->version : 0;
        $pruned = $stream instanceof TraceStream ? $stream->pruned_version : 0;

        throw_if(
            condition: $position > $current,
            exception: new CursorExpiredException('The cursor is ahead of the source, the source was reset: reconcile is required.'),
        );

        throw_if(
            condition: $position < $pruned,
            exception: new CursorExpiredException('The cursor points before the oldest retained change: reconcile is required.'),
        );

        $changes = $this->changesAfter($stream, $position, $current, $limit + 1);

        $hasMore = $changes->count() > $limit;
        $page = $changes->take($limit)->values();

        $models = $source->lookupQuery()
            ->whereIn($source->keyName(), $page->pluck('model_key')->unique()->all())
            ->get()
            ->keyBy(fn (Model $model): string => $source->keyOf($model));

        $data = [];

        foreach ($page as $change) {
            $model = $models->get($change->model_key);

            $data[] = filled($model) && $source->isMember($model)
                ? ['version' => (string) $change->version, 'key' => $change->model_key, 'operation' => Operation::Upsert->value, 'attributes' => $source->attributesFor($model)]
                : ['version' => (string) $change->version, 'key' => $change->model_key, 'operation' => Operation::Delete->value];
        }

        $last = $page->last();
        $next = $last instanceof TraceChange ? $last->version : $position;

        return [
            'data' => $data,
            'meta' => [
                'protocol' => 1,
                'instance_id' => $instanceId,
                'feed' => $feed,
                'schema_hash' => $source->schemaHash($feed),
                'watermark' => (string) $current,
                'next_cursor' => new Cursor(CursorType::Changes, $feed, $instanceId, $next)->encode(),
                'has_more' => $hasMore,
            ],
        ];
    }

    /**
     * @return Collection<int, TraceChange>
     */
    private function changesAfter(?TraceStream $stream, int $position, int $current, int $limit): Collection
    {
        if (! $stream instanceof TraceStream) {
            return new Collection;
        }

        return TraceChange::query()
            ->where('stream_id', $stream->id)
            ->where('version', '>', $position)
            ->where('version', '<=', $current)
            ->orderBy('version')
            ->limit($limit)
            ->get();
    }
}
