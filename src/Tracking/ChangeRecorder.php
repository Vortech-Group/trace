<?php

declare(strict_types=1);

namespace Vortech\Trace\Tracking;

use Illuminate\Database\Eloquent\Model;
use Throwable;
use Vortech\Trace\Enums\ChangeType;
use Vortech\Trace\Exceptions\ConnectionMismatchException;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Support\ModelType;

/** Writes one durable change log row, synchronously, on the connection of the model. */
final readonly class ChangeRecorder
{
    public function __construct(private StreamSequencer $sequencer) {}

    /**
     * Inside the caller's transaction a failure propagates and rolls the model write back
     * with it. Outside any transaction the write is best effort: it is reported, never swallowed silently.
     *
     * @throws ConnectionMismatchException
     * @throws Throwable
     */
    public function record(Model $model, ChangeType $type): void
    {
        $connection = new TraceStream()->getConnection();

        throw_if(
            condition: $model->getConnection()->getName() !== $connection->getName(),
            exception: new ConnectionMismatchException(
                'The model '.$model::class." writes on [{$model->getConnection()->getName()}] but the trace tables live on [{$connection->getName()}]: an atomic change log needs one connection.",
            ),
        );

        $write = function () use ($model, $type): void {
            ['stream' => $stream, 'version' => $version] = $this->sequencer->next(ModelType::of($model));

            TraceChange::query()->create([
                'stream_id' => $stream->id,
                'version' => $version,
                'model_key' => $this->keyOf($model),
                'operation' => $type,
            ]);
        };

        if ($connection->transactionLevel() > 0) {
            $connection->transaction($write);

            return;
        }

        try {
            $connection->transaction($write);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function keyOf(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
