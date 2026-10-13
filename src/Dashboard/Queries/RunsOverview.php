<?php

declare(strict_types=1);

namespace Vortech\Trace\Dashboard\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Vortech\Trace\Dashboard\Data\RunRow;
use Vortech\Trace\Enums\RunStatus;
use Vortech\Trace\Enums\SyncMode;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Support\ModelType;
use Vortech\Trace\Support\TraceConfig;

/** The complete sync run history, filterable and paged. */
final readonly class RunsOverview
{
    public const int PER_PAGE = 25;

    public function __construct(private RunPresenter $presenter) {}

    public function available(): bool
    {
        return Schema::connection(TraceConfig::connection())->hasTable('trace_sync_runs');
    }

    /**
     * @return array{rows: list<RunRow>, total: int, pages: int, page: int}
     */
    public function page(?string $model, ?RunStatus $status, ?SyncMode $mode, int $page): array
    {
        $query = $this->filtered($model, $status, $mode);
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));

        $rows = array_values($query
            ->latest('created_at')
            ->latest('id')
            ->forPage($page, self::PER_PAGE)
            ->get()
            ->map(fn (TraceSyncRun $run): RunRow => $this->presenter->present($run))
            ->all());

        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * The models that have runs, for the filter: model type => short name.
     *
     * @return array<string, string>
     */
    public function models(): array
    {
        $options = [];

        foreach (TraceSyncRun::query()->distinct()->orderBy('model_type')->pluck('model_type')->all() as $type) {
            if (is_string($type)) {
                $options[$type] = class_basename(ModelType::classFor($type) ?? $type);
            }
        }

        return $options;
    }

    public function count(?RunStatus $status = null, bool $today = false): int
    {
        return $this->filtered(null, $status, null)
            ->when($today, fn (Builder $query) => $query->where('created_at', '>=', now()->startOfDay()))
            ->count();
    }

    /**
     * @return Builder<TraceSyncRun>
     */
    private function filtered(?string $model, ?RunStatus $status, ?SyncMode $mode): Builder
    {
        return TraceSyncRun::query()
            ->when(filled($model), fn (Builder $query) => $query->where('model_type', $model))
            ->when($status instanceof RunStatus, fn (Builder $query) => $query->where('status', $status?->value))
            ->when($mode instanceof SyncMode, fn (Builder $query) => $query->where('mode', $mode?->value));
    }
}
