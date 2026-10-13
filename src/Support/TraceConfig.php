<?php

declare(strict_types=1);

namespace Vortech\Trace\Support;

use Throwable;
use Vortech\Trace\Exceptions\InvalidConfigurationException;

final readonly class TraceConfig
{
    public static function connection(): ?string
    {
        $connection = config('trace.connection');

        return is_string($connection) && filled($connection) ? $connection : null;
    }

    public static function sourceToken(): ?string
    {
        $token = config('trace.source.token');

        return is_string($token) && filled($token) ? $token : null;
    }

    public static function pageSize(): int
    {
        return max(1, config()->integer('trace.source.page_size', 500));
    }

    public static function maxPageSize(): int
    {
        return max(self::pageSize(), config()->integer('trace.source.max_page_size', 1000));
    }

    public static function retentionDays(): int
    {
        return max(1, config()->integer('trace.source.retention_days', 30));
    }

    public static function rateLimit(): int
    {
        return max(0, config()->integer('trace.source.rate_limit', 120));
    }

    public static function snapshotCursorTtl(): int
    {
        return max(1, config()->integer('trace.source.snapshot_cursor_ttl', 1440));
    }

    /**
     * @return list<string>
     */
    public static function forbiddenFields(): array
    {
        return self::strings(config('trace.source.forbidden_fields', []));
    }

    /**
     * @return list<string>
     */
    public static function remoteModels(): array
    {
        return self::strings(config('trace.remote.models', []));
    }

    /**
     * @return array{url: string, token: string}
     *
     * @throws InvalidConfigurationException
     * @throws Throwable
     */
    public static function remote(string $connection): array
    {
        $settings = $connection === 'default'
            ? config('trace.remote.default')
            : config("trace.remote.connections.{$connection}");

        throw_unless(
            condition: is_array($settings),
            exception: new InvalidConfigurationException("The remote connection [{$connection}] is not configured."),
        );

        $url = $settings['url'] ?? null;
        $token = $settings['token'] ?? null;

        throw_if(
            condition: ! is_string($url) || blank($url) || ! is_string($token) || blank($token),
            exception: new InvalidConfigurationException("The remote connection [{$connection}] needs both a url and a token."),
        );

        return ['url' => rtrim($url, '/'), 'token' => $token];
    }

    public static function timeout(): int
    {
        return max(1, config()->integer('trace.remote.timeout', 30));
    }

    public static function retries(): int
    {
        return max(1, config()->integer('trace.remote.retries', 3));
    }

    public static function queue(): string
    {
        return config()->string('trace.remote.queue', 'default');
    }

    public static function lockTtl(): int
    {
        return max(1, config()->integer('trace.remote.lock_ttl', 3600));
    }

    public static function runsRetentionDays(): int
    {
        return max(1, config()->integer('trace.remote.runs_retention_days', 30));
    }

    public static function dashboardEnabled(): bool
    {
        return config()->boolean('trace.dashboard.enabled', false);
    }

    public static function dashboardPath(): string
    {
        return trim(config()->string('trace.dashboard.path', 'trace'), '/');
    }

    /**
     * @return list<string>
     */
    public static function dashboardMiddleware(): array
    {
        return self::strings(config('trace.dashboard.middleware', ['web']));
    }

    public static function requireSoftDeletes(): bool
    {
        return config()->boolean('trace.safety.require_soft_deletes', true);
    }

    public static function maxMissingDeletionRatio(): float
    {
        return max(0.0, min(1.0, config()->float('trace.safety.max_missing_deletion_ratio', 0.20)));
    }

    public static function minRecordsForRatioGuard(): int
    {
        return max(0, config()->integer('trace.safety.min_records_for_ratio_guard', 100));
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        $strings = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item) && filled($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
