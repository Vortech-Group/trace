<?php

declare(strict_types=1);

namespace Vortech\Trace\Tracking;

use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use Vortech\Trace\Models\TraceStream;

/**
 * Hands out per-stream versions in commit order. The stream row is locked with
 * SELECT ... FOR UPDATE and stays locked until the surrounding transaction ends, so a
 * higher version can never commit before a lower one.
 */
final readonly class StreamSequencer
{
    /**
     * Must run inside the transaction of the change log insert.
     *
     * @return array{stream: TraceStream, version: int}
     */
    public function next(string $modelType): array
    {
        $stream = $this->lockedStream($modelType);
        $version = $stream->version + 1;

        $stream->forceFill(['version' => $version])->save();

        return ['stream' => $stream, 'version' => $version];
    }

    private function lockedStream(string $modelType): TraceStream
    {
        $stream = $this->lock($modelType);

        if ($stream instanceof TraceStream) {
            return $stream;
        }

        try {
            // a savepoint inside the caller's transaction: a lost race must not poison it (PostgreSQL)
            $stream = (new TraceStream)->getConnection()->transaction(
                fn (): TraceStream => TraceStream::query()->create(['model_type' => $modelType, 'version' => 0]),
            );
        } catch (UniqueConstraintViolationException) {
            // someone else created it first, lock theirs
        }

        return $this->lock($modelType) ?? throw new LogicException("The trace stream for [{$modelType}] vanished.");
    }

    private function lock(string $modelType): ?TraceStream
    {
        return TraceStream::query()->where('model_type', $modelType)->lockForUpdate()->first();
    }
}
