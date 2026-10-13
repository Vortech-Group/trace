<?php

declare(strict_types=1);

namespace Vortech\Trace\Models;

use Illuminate\Database\Eloquent\Model;
use Vortech\Trace\Models\Concerns\UsesTraceConnection;

/**
 * @property string $key
 * @property string $value
 */
final class TraceMeta extends Model
{
    use UsesTraceConnection;

    public $incrementing = false;

    protected $table = 'trace_meta';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];
}
