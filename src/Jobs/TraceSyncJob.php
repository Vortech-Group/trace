<?php

declare(strict_types=1);

namespace Vortech\Trace\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vortech\Trace\Data\SyncRequest;
use Vortech\Trace\Exceptions\RemoteUnavailableException;
use Vortech\Trace\Exceptions\SyncAlreadyRunningException;
use Vortech\Trace\Remote\SyncEngine;
use Vortech\Trace\Support\TraceConfig;

/** Carries only scalars. Retries what may heal (timeouts, 429, 5xx) and fails the rest at once. */
final class TraceSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public int $timeout;

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public readonly string $model,
        public readonly bool $reconcile = false,
        public readonly bool $allowMassDeletion = false,
    ) {
        $this->tries = TraceConfig::retries();
        // below the run lock TTL, so a killed job never outlives its lock
        $this->timeout = max(1, TraceConfig::lockTtl() - 30);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * @throws Throwable
     */
    public function handle(SyncEngine $engine): void
    {
        try {
            $engine->run(new SyncRequest($this->model, $this->reconcile, $this->allowMassDeletion));
        } catch (SyncAlreadyRunningException) {
            // exactly one active sync per model: the running one does the work
            Log::info('trace.sync.skipped', ['model' => $this->model, 'reason' => 'already_running']);
        } catch (RemoteUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // authentication, schema, mapping or data errors do not heal by retrying
            $this->fail($exception);
        }
    }
}
