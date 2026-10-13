<?php

declare(strict_types=1);

namespace Vortech\Trace\Tracking;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Throwable;
use Vortech\Trace\Enums\ChangeType;

/**
 * The one wildcard Eloquent listener. It looks at the lifecycle event, then at the
 * #[Traceable] marker of the model, and writes the change log synchronously.
 *
 * `forceDeleted` is ignored on purpose: a force delete fires `deleted` first, so the
 * tombstone is already logged exactly once. A restore saves the model (`updated`) and
 * then fires `restored`, so the `updated` of a restore is skipped for the same reason.
 */
final readonly class TraceableEventListener
{
    public function __construct(
        private TraceableRegistry $registry,
        private ChangeRecorder $recorder,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @throws Throwable
     */
    public function handle(string $event, array $payload): void
    {
        $colon = strpos($event, ':');

        if ($colon === false || ! str_starts_with($event, 'eloquent.')) {
            return;
        }

        $model = $payload[0] ?? null;

        if (! $model instanceof Model) {
            return;
        }

        $type = match (substr($event, 9, $colon - 9)) {
            'created', 'restored' => ChangeType::Changed,
            'updated' => $this->isRestoreSave($model) ? null : ChangeType::Changed,
            'deleted' => ChangeType::Deleted,
            default => null,
        };

        if (filled($type) && $this->registry->isTraceable($model::class)) {
            $this->recorder->record($model, $type);
        }
    }

    private function isRestoreSave(Model $model): bool
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true) || ! method_exists($model, 'getDeletedAtColumn')) {
            return false;
        }

        $column = $model->getDeletedAtColumn();

        return is_string($column) && $model->wasChanged($column) && $model->getAttribute($column) === null;
    }
}
