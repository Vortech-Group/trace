<?php

declare(strict_types=1);

namespace Vortech\Trace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vortech\Trace\Models\Concerns\UsesTraceConnection;

/**
 * @property int $id
 * @property string $model_type
 * @property int $version
 * @property int $pruned_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TraceStream extends Model
{
    use UsesTraceConnection;

    protected $table = 'trace_streams';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['version' => 'integer', 'pruned_version' => 'integer'];
    }
}
