<?php

declare(strict_types=1);

namespace Vortech\Trace\Dashboard\Data;

final readonly class RunRow
{
    public function __construct(
        public string $mode,
        public string $tone,
        public string $status,
        public string $when,
        public string $duration,
        public string $summary,
        public string $model = '',
        public string $feed = '',
        public string $at = '',
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'tone' => $this->tone,
            'status' => $this->status,
            'when' => $this->when,
            'duration' => $this->duration,
            'summary' => $this->summary,
            'model' => $this->model,
            'feed' => $this->feed,
            'at' => $this->at,
        ];
    }
}
