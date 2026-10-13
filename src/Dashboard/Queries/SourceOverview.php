<?php

declare(strict_types=1);

namespace Vortech\Trace\Dashboard\Queries;

use Illuminate\Support\Facades\Schema;
use Throwable;
use Vortech\Trace\Dashboard\Data\FeedCard;
use Vortech\Trace\Enums\CaptureMode;
use Vortech\Trace\Health\HealthChecker;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceMeta;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Source;
use Vortech\Trace\Source\SourceRegistry;
use Vortech\Trace\Support\TraceConfig;
use Vortech\Trace\Tracking\TraceableRegistry;

/** What the source side publishes and how each feed is captured, from the local trace tables only. */
final readonly class SourceOverview
{
    public function __construct(
        private SourceRegistry $sources,
        private TraceableRegistry $traceables,
        private HealthChecker $health,
    ) {}

    /**
     * @return list<FeedCard>
     */
    public function feeds(): array
    {
        if (! $this->hasTables()) {
            return [];
        }

        $cards = [];

        foreach ($this->sources->all() as $feed => $class) {
            try {
                $cards[] = $this->card($feed, $class);
            } catch (Throwable $exception) {
                $cards[] = new FeedCard($feed, $class, $feed, 'Unknown', $exception->getMessage(), 'bad', 'Misconfigured', $exception->getMessage(), 'php artisan trace:doctor', 0, 0, 0);
            }
        }

        return $cards;
    }

    /**
     * Changes per hour of the last 24 hours, oldest hour first.
     *
     * @return list<array{hour: string, count: int}>
     */
    public function hourly(FeedCard $feed): array
    {
        $stream = TraceStream::query()->where('model_type', Source::resolve($this->sources->all()[$feed->feed])->morphClass())->first();
        $start = now()->startOfHour()->subHours(23);
        $columns = [];
        $bindings = [];

        for ($i = 0; $i < 24; $i++) {
            $columns[] = "coalesce(sum(case when created_at >= ? and created_at < ? then 1 else 0 end), 0) as h{$i}";
            $bindings[] = $start->copy()->addHours($i);
            $bindings[] = $start->copy()->addHours($i + 1);
        }

        $row = $stream instanceof TraceStream
            ? TraceChange::query()->where('stream_id', $stream->id)->where('created_at', '>=', $start)->toBase()->selectRaw(implode(', ', $columns), $bindings)->first()
            : null;
        $counts = is_object($row) ? get_object_vars($row) : [];

        $hours = [];

        for ($i = 0; $i < 24; $i++) {
            $value = $counts["h{$i}"] ?? 0;
            $hours[] = ['hour' => $start->copy()->addHours($i)->format('H:i'), 'count' => is_numeric($value) ? (int) $value : 0];
        }

        return $hours;
    }

    /** @return array{at: string, changes: int, runs: int}|null */
    public function lastPrune(): ?array
    {
        $value = TraceMeta::query()->whereKey('prune.last')->value('value');
        $data = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($data) || ! is_string($data['at'] ?? null)) {
            return null;
        }

        return ['at' => $data['at'], 'changes' => is_int($data['changes'] ?? null) ? $data['changes'] : 0, 'runs' => is_int($data['runs'] ?? null) ? $data['runs'] : 0];
    }

    public function instanceId(): string
    {
        $id = TraceMeta::query()->whereKey('instance_id')->value('value');

        return is_string($id) ? $id : 'not created yet';
    }

    /**
     * @param  class-string<Source>  $class
     *
     * @throws Throwable
     */
    private function card(string $feed, string $class): FeedCard
    {
        $source = Source::resolve($class);
        $model = $source->model();
        $mode = $this->traceables->captureFor($model) ?? CaptureMode::Events;
        $stream = TraceStream::query()->where('model_type', $source->morphClass())->first();

        $issue = null;

        try {
            $this->health->capture($class);
        } catch (Throwable $exception) {
            $issue = $exception->getMessage();
        }

        $database = $mode->is(CaptureMode::Database);
        $logRows = $stream instanceof TraceStream ? TraceChange::query()->where('stream_id', $stream->id)->count() : 0;
        $today = $stream instanceof TraceStream ? TraceChange::query()->where('stream_id', $stream->id)->where('created_at', '>=', now()->startOfDay())->count() : 0;
        $pruned = $stream instanceof TraceStream ? $stream->pruned_version : 0;

        return new FeedCard(
            feed: $feed,
            model: $model,
            name: class_basename($model),
            capture: $database ? 'Database triggers' : 'Model events',
            info: $stream instanceof TraceStream
                ? 'Version '.number_format($stream->version, 0, '', ' ').' · '.number_format($logRows, 0, '', ' ').' log rows kept · '.($pruned > 0 ? 'pruned up to '.number_format($pruned, 0, '', ' ') : 'nothing pruned yet')
                : 'No change recorded yet',
            tone: $issue === null ? '' : 'warn',
            status: match (true) {
                $issue !== null => $database ? 'Triggers need attention' : 'Leftover triggers',
                $database => 'Triggers installed',
                default => 'Eloquent listener',
            },
            issue: $issue,
            command: match (true) {
                $issue === null && $database => 'php artisan trace:triggers status',
                $issue === null => 'php artisan trace:doctor',
                $database => 'php artisan trace:triggers install',
                default => "php artisan trace:triggers remove --model={$model}",
            },
            logRows: $logRows,
            today: $today,
            prunedVersion: $pruned,
            database: $database,
        );
    }

    private function hasTables(): bool
    {
        $schema = Schema::connection(TraceConfig::connection());

        return $schema->hasTable('trace_streams') && $schema->hasTable('trace_changes') && $schema->hasTable('trace_meta');
    }
}
