<?php

declare(strict_types=1);

namespace Vortech\Trace\Dashboard\Data;

final readonly class FeedCard
{
    /**
     * @param  class-string  $model
     */
    public function __construct(
        public string $feed,
        public string $model,
        public string $name,
        public string $capture,
        public string $info,
        public string $tone,
        public string $status,
        public ?string $issue,
        public string $command,
        public int $logRows,
        public int $today,
        public int $prunedVersion,
        public bool $database = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'feed' => $this->feed,
            'model' => $this->model,
            'name' => $this->name,
            'capture' => $this->capture,
            'info' => $this->info,
            'tone' => $this->tone,
            'status' => $this->status,
            'issue' => $this->issue,
            'command' => $this->command,
            'logRows' => $this->logRows,
            'today' => $this->today,
            'prunedVersion' => $this->prunedVersion,
            'database' => $this->database,
        ];
    }
}
