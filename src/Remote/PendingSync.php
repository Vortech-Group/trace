<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
use LogicException;
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

    private bool $dryRun = false;

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
     * Rehearses the run: the source is read and every record applied in a transaction that is rolled back, so the
     * result tells what the run would create, match, update and delete while nothing is written, no model event
     * fires and no sync state moves. Only run() can rehearse, a queued job has nowhere to report to.
     */
    public function dryRun(bool $dryRun = true): self
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    /**
     * @throws Throwable
     */
    public function run(): SyncResult
    {
        return app(SyncEngine::class)->run($this->request());
    }

    /**
     * @throws LogicException
     */
    public function dispatch(): PendingDispatch
    {
        throw_if($this->dryRun, new LogicException('A dry run cannot be queued: call run() to see its result.'));

        $pending = TraceSyncJob::dispatch($this->model, $this->reconcile, $this->allowMassDeletion)
            ->onQueue($this->queue ?? TraceConfig::queue());

        return blank($this->connection) ? $pending : $pending->onConnection($this->connection);
    }

    private function request(): SyncRequest
    {
        return new SyncRequest($this->model, $this->reconcile, $this->allowMassDeletion, $this->dryRun);
    }
}
