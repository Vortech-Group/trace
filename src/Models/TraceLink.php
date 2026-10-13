<?php

declare(strict_types=1);

namespace Vortech\Trace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vortech\Trace\Models\Concerns\UsesTraceConnection;

/**
 * @property int $id
 * @property string $source_instance_id
 * @property string $source_connection
 * @property string $feed
 * @property string $remote_key
 * @property string $model_type
 * @property string $model_key
 * @property string|null $last_seen_run_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TraceLink extends Model
{
    use UsesTraceConnection;

    protected $table = 'trace_links';

    protected $guarded = [];
}
