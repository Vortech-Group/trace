<?php

declare(strict_types=1);

namespace Vortech\Trace\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vortech\Trace\Enums\RunStatus;
use Vortech\Trace\Enums\SyncMode;
use Vortech\Trace\Models\Concerns\UsesTraceConnection;

/**
 * @property string $id
 * @property string|null $source_instance_id
 * @property string $feed
 * @property string $model_type
 * @property SyncMode $mode
 * @property RunStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int $created
 * @property int $updated
 * @property int $unchanged
 * @property int $deleted
 * @property int $restored
 * @property int $errors
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TraceSyncRun extends Model
{
    use HasUuids;
    use UsesTraceConnection;

    protected $table = 'trace_sync_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mode' => SyncMode::class,
            'status' => RunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
