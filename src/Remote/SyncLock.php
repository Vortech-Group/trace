<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Vortech\Trace\Data\SyncDefinition;
use Vortech\Trace\Exceptions\InvalidConfigurationException;
use Vortech\Trace\Exceptions\SyncAlreadyRunningException;
use Vortech\Trace\Support\TraceConfig;

/**
 * One active run per connection + feed + local model, for run() and for queued jobs alike.
 * The source instance is pinned to the connection by the sync state, so it is not part of the key.
 */
final readonly class SyncLock
{
    /**
     * @throws SyncAlreadyRunningException
     * @throws InvalidConfigurationException
     * @throws Throwable
     */
    public function acquire(SyncDefinition $definition): Lock
    {
        throw_unless(
            condition: Cache::getStore() instanceof LockProvider,
            exception: new InvalidConfigurationException('The default cache store does not support atomic locks; Trace needs one (redis, database, file, memcached ...).'),
        );

        $lock = Cache::lock(self::key($definition), TraceConfig::lockTtl());

        throw_unless(
            condition: $lock->get(),
            exception: new SyncAlreadyRunningException("A sync of [{$definition->model}] is already running."),
        );

        return $lock;
    }

    public static function key(SyncDefinition $definition): string
    {
        return "trace:sync:{$definition->connection}:{$definition->feed}:{$definition->model}";
    }
}
