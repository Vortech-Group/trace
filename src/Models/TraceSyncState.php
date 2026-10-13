<?php

declare(strict_types=1);

namespace Vortech\Trace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vortech\Trace\Enums\SyncStatus;
use Vortech\Trace\Models\Concerns\UsesTraceConnection;

/**
 * @property int $id
 * @property string $source_connection
 * @property string|null $source_instance_id
 * @property string $feed
 * @property string $model_type
 * @property string|null $schema_hash
 * @property string|null $cursor
 * @property SyncStatus $status
 * @property string|null $bootstrap_cursor
 * @property string|null $bootstrap_watermark
 * @property string|null $bootstrap_run_id
 * @property Carbon|null $last_success_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TraceSyncState extends Model
{
    use UsesTraceConnection;

    protected $table = 'trace_sync_states';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => SyncStatus::class, 'last_success_at' => 'datetime'];
    }

    public function inBootstrap(): bool
    {
        return filled($this->bootstrap_watermark);
    }
}
