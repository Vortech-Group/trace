<?php

declare(strict_types=1);

namespace Vortech\Trace\Data;

use Illuminate\Database\Eloquent\Model;

/** Everything a run needs that can travel through a queue: plain scalars only. A dry run only ever runs in place. */
final readonly class SyncRequest
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $model,
        public bool $reconcile = false,
        public bool $allowMassDeletion = false,
        public bool $dryRun = false,
    ) {}
}
