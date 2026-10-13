<?php

declare(strict_types=1);

namespace Vortech\Trace\Dashboard\Queries;

use Vortech\Trace\Dashboard\Data\RunRow;
use Vortech\Trace\Enums\RunStatus;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Support\ModelType;

/** Turns a sync run into what the dashboard prints. */
final readonly class RunPresenter
{
    public function present(TraceSyncRun $run): RunRow
    {
        $seconds = $run->started_at !== null && $run->finished_at !== null ? $run->started_at->diffInMilliseconds($run->finished_at) / 1000 : null;
        $counts = "+{$run->created}  ~{$run->updated}  −{$run->deleted}";
        $at = $run->started_at ?? $run->created_at;

        return new RunRow(
            mode: ucfirst($run->mode->value),
            tone: match ($run->status) {
                RunStatus::Failed => 'bad',
                RunStatus::Running => 'warn',
                RunStatus::Completed => '',
            },
            status: ucfirst($run->status->value),
            when: $at?->diffForHumans() ?? '—',
            duration: $seconds === null ? '—' : ($seconds >= 60 ? floor($seconds / 60).' m '.((int) $seconds % 60).' s' : number_format($seconds, 1).' s'),
            summary: $run->status->is(RunStatus::Failed) && filled($run->last_error) ? $run->last_error : $counts,
            model: class_basename(ModelType::classFor($run->model_type) ?? $run->model_type),
            feed: $run->feed,
            at: $at?->format('Y-m-d H:i:s') ?? '',
        );
    }
}
