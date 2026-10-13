<?php

declare(strict_types=1);

namespace Vortech\Trace\Data;

use Illuminate\Database\Eloquent\Model;
use Vortech\Trace\Mapper;

/** The validated meaning of a model's #[Sync] attribute. */
final readonly class SyncDefinition
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $model,
        public string $feed,
        public Mapper $mapper,
        public string $connection,
    ) {}

    public function newModel(): Model
    {
        return new ($this->model);
    }

    public function morphClass(): string
    {
        return $this->newModel()->getMorphClass();
    }
}
