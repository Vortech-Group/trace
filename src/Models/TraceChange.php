<?php

declare(strict_types=1);

namespace Vortech\Trace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vortech\Trace\Enums\ChangeType;
use Vortech\Trace\Models\Concerns\UsesTraceConnection;

/**
 * @property int $id
 * @property int $stream_id
 * @property int $version
 * @property string $model_key
 * @property ChangeType $operation
 * @property Carbon|null $created_at
 */
final class TraceChange extends Model
{
    use UsesTraceConnection;

    public const UPDATED_AT = null;

    protected $table = 'trace_changes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['stream_id' => 'integer', 'version' => 'integer', 'operation' => ChangeType::class];
    }
}
