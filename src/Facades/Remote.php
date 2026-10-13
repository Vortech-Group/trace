<?php

declare(strict_types=1);

namespace Vortech\Trace\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Vortech\Trace\Remote\RemoteBuilder;
use Vortech\Trace\Remote\RemoteManager;

/**
 * @method static RemoteBuilder for(class-string<Model> $model)
 *
 * @see RemoteManager
 */
final class Remote extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RemoteManager::class;
    }
}
