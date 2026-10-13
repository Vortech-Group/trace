<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Illuminate\Database\Eloquent\Model;
use Vortech\Trace\Data\SyncDefinition;

/** The model, its connection and its #[Sync] metadata, validated. */
final readonly class RemoteBuilder
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        private string $model,
        private SyncDefinition $definition,
    ) {}

    public function definition(): SyncDefinition
    {
        return $this->definition;
    }

    /** Pulls what changed since the last run (the first run does a full bootstrap). */
    public function sync(): PendingSync
    {
        return new PendingSync($this->model);
    }

    /** Compares the complete state and repairs what events could not tell. */
    public function reconcile(): PendingSync
    {
        return new PendingSync($this->model, reconcile: true);
    }
}
