<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Illuminate\Database\Eloquent\Model;
use Throwable;
use Vortech\Trace\Data\ChangeRecord;
use Vortech\Trace\Data\SyncContext;
use Vortech\Trace\Enums\Operation;
use Vortech\Trace\Models\TraceLink;

/**
 * Applies one record idempotently: the same record twice never duplicates a row and never
 * issues an UPDATE for values that did not change. Only linked records are ever deleted.
 */
final readonly class RecordApplier
{
    public function __construct(
        private RecordResolver $resolver,
        private AttributeMapper $attributes,
    ) {}

    /**
     * @throws Throwable
     */
    public function apply(SyncContext $context, ChangeRecord $record): void
    {
        if ($record->operation->is(Operation::Delete)) {
            $this->delete($context, $record);

            return;
        }

        $this->upsert($context, $record);
    }

    /**
     * @throws Throwable
     */
    private function upsert(SyncContext $context, ChangeRecord $record): void
    {
        $mapper = $context->definition->mapper;
        $values = $this->attributes->map($mapper, $record->attributes);

        $link = $this->resolver->link($context, $record->key);
        $model = $link instanceof TraceLink ? $this->resolver->linkedModel($context, $link) : null;

        if ($link instanceof TraceLink && ! $model instanceof Model) {
            // the local row is gone for good: the link is stale and the record is created again
            $link->delete();
            $link = null;
        }

        $matchValues = [];

        if (! $model instanceof Model) {
            $matchValues = $this->attributes->matchValues($mapper, $record->attributes);
            $model = $this->resolver->matchedModel($context, $record->key, $matchValues);

            if ($model instanceof Model) {
                $context->tally->matched++;
            }
        }

        if (! $model instanceof Model) {
            $model = $context->definition->newModel();
            // create-only: the match columns are set now and never touched by fields() later
            $model->forceFill($matchValues + $values)->save();
            $this->link($context, $record->key, $model);
            $context->tally->created++;

            return;
        }

        $restored = false;

        if (method_exists($model, 'trashed') && $model->trashed() && method_exists($model, 'restore')) {
            $model->restore();
            $restored = true;
            $context->tally->restored++;
        }

        $model->forceFill($values);

        if ($model->isDirty()) {
            $model->save();
            $context->tally->updated++;
        } elseif (! $restored) {
            $context->tally->unchanged++;
        }

        if (! $link instanceof TraceLink) {
            $this->link($context, $record->key, $model);
        }
    }

    /**
     * @throws Throwable
     */
    private function delete(SyncContext $context, ChangeRecord $record): void
    {
        $link = $this->resolver->link($context, $record->key);
        $model = $link instanceof TraceLink ? $this->resolver->linkedModel($context, $link) : null;

        if (
            $model instanceof Model
            && method_exists($model, 'trashed')
            && ! $model->trashed()
        ) {
            $model->delete();
            $context->tally->deleted++;

            return;
        }

        $context->tally->unchanged++;
    }

    private function link(SyncContext $context, string $remoteKey, Model $model): void
    {
        $key = $model->getKey();

        TraceLink::query()->create([
            'source_instance_id' => $context->instanceId,
            'source_connection' => $context->definition->connection,
            'feed' => $context->definition->feed,
            'remote_key' => $remoteKey,
            'model_type' => $context->definition->morphClass(),
            'model_key' => is_scalar($key) ? (string) $key : '',
            'last_seen_run_id' => $context->seenMarker,
        ]);
    }
}
