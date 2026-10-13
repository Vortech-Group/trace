<?php

declare(strict_types=1);

namespace Vortech\Trace\Dashboard\Data;

use Carbon\CarbonInterface;

final readonly class RemoteModel
{
    /**
     * @param  class-string  $class
     * @param  list<RunRow>  $runs
     */
    public function __construct(
        public string $class,
        public string $type,
        public string $name,
        public string $feed,
        public string $tone,
        public string $status,
        public bool $needsAttention,
        public bool $synced,
        public int $links,
        public ?CarbonInterface $lastSuccess,
        public string $cursor,
        public string $schema,
        public string $instance,
        public ?string $note,
        public array $runs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'type' => $this->type,
            'name' => $this->name,
            'feed' => $this->feed,
            'tone' => $this->tone,
            'status' => $this->status,
            'needsAttention' => $this->needsAttention,
            'synced' => $this->synced,
            'links' => $this->links,
            'lastSuccess' => $this->lastSuccess?->diffForHumans(),
            'cursor' => $this->cursor,
            'schema' => $this->schema,
            'instance' => $this->instance,
            'note' => $this->note,
            'runs' => array_map(fn (RunRow $run): array => $run->toArray(), $this->runs),
        ];
    }
}
