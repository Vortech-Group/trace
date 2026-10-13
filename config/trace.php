<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The connection that stores the trace_* tables. When it is null, the default
    | connection is used. On the source side it must be the connection of the
    | traceable models: a model write and its change log row can only commit
    | together on one connection (any other setup fails fast).
    |
    */

    'connection' => env('TRACE_DB_CONNECTION'),

    'source' => [

        /*
        | Shared secret the remote sends as a bearer token. Without it the feed is
        | closed (503), never public.
        */
        'token' => env('TRACE_SOURCE_TOKEN'),

        // Default and maximum number of records per page.
        'page_size' => 500,
        'max_page_size' => 1000,

        // Change log rows older than this are removed by trace:prune.
        'retention_days' => 30,

        // Requests per minute and client IP, 0 disables the limiter.
        'rate_limit' => 120,

        // A snapshot cursor stops working after this many minutes.
        'snapshot_cursor_ttl' => 1440,

        // Fields that can never be published, even when a Source lists them.
        'forbidden_fields' => ['password', 'remember_token', 'api_token', 'token', 'secret'],
    ],

    'remote' => [

        'default' => [
            'url' => env('TRACE_REMOTE_URL'),
            'token' => env('TRACE_REMOTE_TOKEN'),
        ],

        // Additional sources, referenced by #[Sync(connection: 'logistics')].
        'connections' => [
            // 'logistics' => ['url' => env('LOGISTICS_TRACE_URL'), 'token' => env('LOGISTICS_TRACE_TOKEN')],
        ],

        // Models trace:doctor should validate besides the ones that already synced.
        'models' => [],

        'timeout' => 30,
        'retries' => 3,
        'queue' => 'default',

        // Seconds the run lock is held at most, keep it above the job timeout.
        'lock_ttl' => 3600,

        // Sync runs older than this are removed by trace:prune.
        'runs_retention_days' => 30,
    ],

    'safety' => [
        'require_soft_deletes' => true,

        // Soft deleting more than this share of the linked records needs an explicit override.
        'max_missing_deletion_ratio' => 0.20,
        'min_records_for_ratio_guard' => 100,
    ],

    'dashboard' => [

        /*
        | The read-mostly dashboard under /{path}. It is off by default. Who may open it is decided by
        | the viewTrace gate, who may start a sync by the manageTrace gate. Both default to the local
        | environment: define them in a service provider to open them up.
        */
        'enabled' => env('TRACE_DASHBOARD_ENABLED', false),

        'path' => 'trace',

        'middleware' => ['web'],
    ],
];
