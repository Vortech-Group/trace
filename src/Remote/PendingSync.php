<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
use Throwable;
use Vortech\Trace\Data\SyncRequest;
use Vortech\Trace\Data\SyncResult;
use Vortech\Trace\Jobs\TraceSyncJob;
use Vortech\Trace\Support\TraceConfig;

/** A described sync. Building it has no side effect: only run() and dispatch() do anything. */
final class PendingSync
{
    private ?string $queue = null;

    private ?string $connection = null;

    private bool $allowMassDeletion = false;

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        private readonly string $model,
        private readonly bool $reconcile = false,
    ) {}

    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function onConnection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    /** Lets a reconcile or bootstrap soft delete more than the configured share of records. */
    public function allowMassDeletion(bool $allow = true): self
    {
        $this->allowMassDeletion = $allow;

        return $this;
    }

    /**
     * @throws Throwable
     */
    public function run(): SyncResult
    {
        return app(SyncEngine::class)->run($this->request());
    }

    public function dispatch(): PendingDispatch
    {
        $pending = TraceSyncJob::dispatch($this->model, $this->reconcile, $this->allowMassDeletion)
            ->onQueue($this->queue ?? TraceConfig::queue());

        return blank($this->connection) ? $pending : $pending->onConnection($this->connection);
    }

    private function request(): SyncRequest
    {
        return new SyncRequest($this->model, $this->reconcile, $this->allowMassDeletion);
    }
}
