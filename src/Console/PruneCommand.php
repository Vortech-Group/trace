<?php

declare(strict_types=1);

namespace Vortech\Trace\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceMeta;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Support\TraceConfig;

final class PruneCommand extends Command
{
    protected $signature = 'trace:prune';

    protected $description = 'Remove change log rows and sync runs past their retention';

    /**
     * @throws Throwable
     */
    public function handle(): int
    {
        $changes = 0;
        $runs = 0;

        if (Schema::connection(TraceConfig::connection())->hasTable('trace_streams')) {
            $cutoff = now()->subDays(TraceConfig::retentionDays());

            foreach (TraceStream::query()->get() as $stream) {
                $highest = TraceChange::query()->where('stream_id', $stream->id)->where('created_at', '<', $cutoff)->max('version');

                if (! is_numeric($highest)) {
                    continue;
                }

                $highest = (int) $highest;

                // the boundary moves first in the same transaction: a cursor below it is answered with 410
                $stream->getConnection()->transaction(function () use ($stream, $highest, &$changes): void {
                    $stream->forceFill(['pruned_version' => max($stream->pruned_version, $highest)])->save();

                    $changes += TraceChange::query()->where('stream_id', $stream->id)->where('version', '<=', $highest)->toBase()->delete();
                });
            }
        }

        if (Schema::connection(TraceConfig::connection())->hasTable('trace_sync_runs')) {
            $runs = TraceSyncRun::query()->where('created_at', '<', now()->subDays(TraceConfig::runsRetentionDays()))->toBase()->delete();
        }

        if (Schema::connection(TraceConfig::connection())->hasTable('trace_meta')) {
            // the dashboard shows when retention last ran
            TraceMeta::query()->updateOrCreate(['key' => 'prune.last'], ['value' => json_encode(['at' => now()->toIso8601String(), 'changes' => $changes, 'runs' => $runs], JSON_THROW_ON_ERROR)]);
        }

        $this->components->info("Pruned {$changes} change log rows and {$runs} sync runs.");

        return self::SUCCESS;
    }
}
