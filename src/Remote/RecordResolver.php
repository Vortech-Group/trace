<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Throwable;
use Vortech\Trace\Data\SyncContext;
use Vortech\Trace\Exceptions\AmbiguousMatchException;
use Vortech\Trace\Models\TraceLink;

/** Finds the local record of a remote key: by link first, by match() only when there is no link yet. */
final readonly class RecordResolver
{
    public function link(SyncContext $context, string $remoteKey): ?TraceLink
    {
        return TraceLink::query()
            ->where('source_instance_id', $context->instanceId)
            ->where('feed', $context->definition->feed)
            ->where('model_type', $context->definition->morphClass())
            ->where('remote_key', $remoteKey)
            ->first();
    }

    public function linkedModel(SyncContext $context, TraceLink $link): ?Model
    {
        return $this->withTrashed($context->definition->newModel()->newQuery())->find($link->model_key);
    }

    /**
     * @param  array<string, mixed>  $matchValues  local column => value
     *
     * @throws AmbiguousMatchException
     * @throws Throwable
     */
    public function matchedModel(SyncContext $context, string $remoteKey, array $matchValues): ?Model
    {
        if (blank($matchValues)) {
            return null;
        }

        $query = $this->withTrashed($context->definition->newModel()->newQuery());

        foreach ($matchValues as $column => $value) {
            $query->where($column, $value);
        }

        $found = $query->limit(2)->get();

        throw_if(
            condition: $found->count() > 1,
            exception: new AmbiguousMatchException("The match of remote record [{$remoteKey}] is ambiguous: more than one local record qualifies."),
        );

        return $found->first();
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function withTrashed(Builder $query): Builder
    {
        return $query->withoutGlobalScope(SoftDeletingScope::class);
    }
}
