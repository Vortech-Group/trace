<?php

declare(strict_types=1);

namespace Vortech\Trace\Dashboard\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;
use Vortech\Trace\Dashboard\Data\FeedCard;
use Vortech\Trace\Dashboard\Data\RemoteModel;
use Vortech\Trace\Dashboard\Data\RunRow;
use Vortech\Trace\Dashboard\Queries\RemoteOverview;
use Vortech\Trace\Dashboard\Queries\RunsOverview;
use Vortech\Trace\Dashboard\Queries\SourceOverview;
use Vortech\Trace\Enums\CheckStatus;
use Vortech\Trace\Enums\RunStatus;
use Vortech\Trace\Enums\SyncMode;
use Vortech\Trace\Facades\Remote;
use Vortech\Trace\Health\Check;
use Vortech\Trace\Health\HealthChecker;
use Vortech\Trace\Support\TraceConfig;

final readonly class DashboardApiController
{
    public function __construct(
        private RemoteOverview $remote,
        private RunsOverview $runs,
        private SourceOverview $source,
        private HealthChecker $health,
    ) {}

    public function remote(): JsonResponse
    {
        $models = $this->remote->models();

        return response()->json([
            'models' => array_map(fn (RemoteModel $model): array => $model->toArray(), $models),
            'stats' => [
                'models' => count($models),
                'synced' => count(array_filter($models, fn (RemoteModel $model): bool => $model->synced)),
                'attention' => count(array_filter($models, fn (RemoteModel $model): bool => $model->needsAttention)),
                'links' => array_sum(array_map(fn (RemoteModel $model): int => $model->links, $models)),
            ],
            'canManage' => Gate::allows('manageTrace'),
        ]);
    }

    public function runs(Request $request): JsonResponse
    {
        if (! $this->runs->available()) {
            return response()->json(['available' => false]);
        }

        $model = $request->string('model')->toString();
        $status = RunStatus::tryFrom($request->string('status')->toString());
        $mode = SyncMode::tryFrom($request->string('mode')->toString());
        $result = $this->runs->page($model === '' ? null : $model, $status, $mode, $request->integer('page', 1));

        return response()->json([
            'available' => true,
            'rows' => array_map(fn (RunRow $run): array => $run->toArray(), $result['rows']),
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => $result['page'],
            'models' => (object) $this->runs->models(),
            'statuses' => array_map(fn (RunStatus $case): string => $case->value, RunStatus::cases()),
            'modes' => array_map(fn (SyncMode $case): string => $case->value, SyncMode::cases()),
            'filters' => ['model' => $model, 'status' => $status instanceof RunStatus ? $status->value : '', 'mode' => $mode instanceof SyncMode ? $mode->value : ''],
            'stats' => ['all' => $this->runs->count(), 'today' => $this->runs->count(today: true), 'failed' => $this->runs->count(RunStatus::Failed)],
        ]);
    }

    public function source(Request $request): JsonResponse
    {
        $feeds = $this->source->feeds();
        $selected = collect($feeds)->first(fn (FeedCard $feed): bool => $feed->feed === $request->query('feed')) ?? ($feeds[0] ?? null);
        // a remote-only application has no source tables to read
        $prune = $feeds === [] ? null : $this->source->lastPrune();

        return response()->json([
            'feeds' => array_map(fn (FeedCard $feed): array => $feed->toArray(), $feeds),
            'selected' => $selected?->feed,
            'hours' => $selected instanceof FeedCard ? $this->source->hourly($selected) : [],
            'stats' => [
                'feeds' => count($feeds),
                'logRows' => array_sum(array_map(fn (FeedCard $feed): int => $feed->logRows, $feeds)),
                'today' => array_sum(array_map(fn (FeedCard $feed): int => $feed->today, $feeds)),
                'attention' => count(array_filter($feeds, fn (FeedCard $feed): bool => $feed->issue !== null)),
            ],
            'prune' => $prune === null ? null : ['at' => Carbon::parse($prune['at'])->diffForHumans(), 'changes' => $prune['changes'], 'runs' => $prune['runs']],
            'instance' => $feeds === [] ? '' : $this->source->instanceId(),
            'retention' => TraceConfig::retentionDays(),
            'canManage' => Gate::allows('manageTrace'),
            'hasTriggers' => count(array_filter($feeds, fn (FeedCard $feed): bool => $feed->database)) > 0,
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        $live = $request->boolean('live');
        $checks = [...$this->health->source(), ...$this->health->remote($live)];

        return response()->json([
            'checks' => array_map(fn (Check $check): array => ['label' => $check->label, 'status' => $check->status->value, 'detail' => $check->detail], $checks),
            'failed' => count(array_filter($checks, fn (Check $check): bool => $check->status->is(CheckStatus::Failed))),
            'live' => $live,
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        return $this->start($request, reconcile: false);
    }

    public function reconcile(Request $request): JsonResponse
    {
        return $this->start($request, reconcile: true);
    }

    public function prune(): JsonResponse
    {
        return $this->artisan('trace:prune');
    }

    public function triggers(): JsonResponse
    {
        return $this->artisan('trace:triggers', ['action' => 'install']);
    }

    private function start(Request $request, bool $reconcile): JsonResponse
    {
        $name = $request->string('model')->toString();
        $class = collect($this->health->remoteModels())->first(fn (string $model): bool => $model === $name);

        abort_if($class === null, 404);

        $verb = $reconcile ? 'Reconcile' : 'Sync';

        try {
            $builder = Remote::for($class);
            $pending = $reconcile ? $builder->reconcile() : $builder->sync();
            $dry = $request->boolean('dry');

            if (! $dry && ! $request->boolean('inline')) {
                $pending->dispatch();

                return $this->result(true, "{$verb} queued for ".class_basename($class));
            }

            // a bootstrap can take longer than a web request is allowed to
            set_time_limit(0);
            $result = $pending->dryRun($dry)->run();
        } catch (Throwable $exception) {
            return $this->result(false, $exception->getMessage());
        }

        if ($result->dryRun) {
            return $this->result(true, "Dry run of a {$result->mode->value}, nothing was written: {$result->created} new, {$result->matched} matched, {$result->updated} updated, {$result->unchanged} unchanged, {$result->deleted} deleted, {$result->restored} restored");
        }

        return $this->result(true, "{$verb} finished in {$result->durationMs} ms: +{$result->created} ~{$result->updated} −{$result->deleted}");
    }

    /**
     * Runs one of the package commands and reports its output.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function artisan(string $command, array $parameters = []): JsonResponse
    {
        $output = new BufferedOutput;

        try {
            $status = Artisan::call($command, $parameters, $output);
        } catch (Throwable $exception) {
            return $this->result(false, $exception->getMessage());
        }

        $message = trim((string) preg_replace(['/\s*\.{3,}\s*/', '/\s+/'], [' · ', ' '], $output->fetch()));
        $message = preg_replace('/^(INFO|ERROR|WARN)\s+/', '', $message) ?? $message;

        return $this->result($status === 0, $message === '' ? "{$command} finished" : $message);
    }

    private function result(bool $ok, string $message): JsonResponse
    {
        return response()->json(['ok' => $ok, 'message' => $message]);
    }
}
