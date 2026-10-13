<?php

declare(strict_types=1);

namespace Vortech\Trace\Data;

use Vortech\Trace\Enums\SyncMode;

final readonly class SyncResult
{
    public function __construct(
        public string $runId,
        public SyncMode $mode,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $deleted,
        public int $restored,
        public int $durationMs,
        public ?string $cursor,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'mode' => $this->mode->value,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'deleted' => $this->deleted,
            'restored' => $this->restored,
            'duration_ms' => $this->durationMs,
            'cursor' => $this->cursor,
        ];
    }
}
