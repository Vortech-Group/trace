# Changelog

All notable changes to `laravel-trace` will be documented in this file

## 0.5.0 - 2026-10-16

### Added

- A Laravel Boost guideline and a `trace-development` skill ship with the package (`resources/boost`), so AI agents working in an application that uses `laravel-trace` pick up how to declare traceable models, write mappers and run syncs

## 0.4.1 - 2026-10-13

### Changed

- PHP 8.3 and Laravel 12 are supported next to PHP 8.4+ and Laravel 13; the suite and PHPStan run on all of them in CI. The code no longer uses PHP 8.4 only syntax (`new X()->method()`), and the Artisan commands declare `$signature` and `$description` instead of the Laravel 13 attributes. The dry run no longer relies on the return type of `Model::withoutEvents()`, which differs between Laravel 12 and 13

## 0.4.0 - 2026-10-13

### Added

- A dashboard under `/trace`, off by default (`TRACE_DASHBOARD_ENABLED`, `trace.dashboard.*`). A small Preact application over a JSON API (`/trace/api`), prebuilt in `dist/` and inlined into the page: nothing to build or publish, and it refreshes itself while the tab is visible
  - **Remote**: every synced model with status, links, cursor, schema, instance and recent runs; **Sync now** and **Reconcile**, queued or run in place
  - **Runs**: the whole sync history, newest first, filterable by model, status and mode, paged
  - **Source**: the published feeds with capture mode and trigger state, changes per hour, retention and the last prune; **Prune now** and **Install triggers**
  - **Health**: the `trace:doctor` checks, the source is only contacted on request
  - Access is decided by the `viewTrace` and `manageTrace` gates, both open in the `local` environment only until you define them; a request that is not allowed gets a `404`
- Dry run: `trace:sync --dry-run`, `Remote::for(...)->sync()->dryRun()->run()` and a checkbox on the dashboard rehearse a bootstrap, an incremental sync or a reconcile in a rolled back transaction and report what it would create, match, update, restore and delete. Nothing is written, the sync state does not move and no model, observer or sync event fires; problems a real run would hit (an ambiguous match, a constraint violation) surface as the error. The command warns when the mapper declares no `match()`, because existing local rows would then be duplicated
- `SyncResult` and the `trace:sync` table report how many records were `matched` to an existing local row; `SyncResult::toArray()` gained `matched` and `dry_run`
- `#[Traceable(alias: ...)]` and `#[Sync(alias: ...)]` name a model in the `model_type` columns instead of its class name, so renaming or moving the class keeps the change log, the links and the sync state valid. Without an alias the morph class (the class name, or its morph map alias) is stored as before
- `trace:prune` remembers when it last ran, the dashboard shows it

### Changed

- Indexes: `trace_changes (stream_id, created_at)` serves the retention, the change counts and the hourly chart, `trace_sync_runs (created_at)` the run history and its pruning, and the single-column `trace_changes.stream_id` index, which the unique `(stream_id, version)` index already covers, is gone (one index less on every captured write). The create migrations changed, so only new installations get them; an existing installation can add them with `$table->index(['stream_id', 'created_at'])` on `trace_changes` and `$table->index('created_at')` on `trace_sync_runs` (and drop `trace_changes_stream_id_index`) in a migration of its own
- The `trace:doctor` checks live in `Vortech\Trace\Health\HealthChecker`, shared with the dashboard
- The Artisan commands are also registered in web requests when the dashboard is enabled, because the dashboard runs `trace:prune` and `trace:triggers`
- The frontend has its own CI job (type check, unit tests, build, and a check that the committed `dist/` is what the sources build to)

## 0.3.0 - 2026-10-13

### Added

- `#[Traceable(capture: CaptureMode::Database)]` writes the change log with database triggers (MySQL, MariaDB, PostgreSQL, SQLite), so writes that fire no model event (`DB::table()`, mass updates, raw SQL, imports) are logged too, in the transaction of the write and in the same commit order as the Eloquent listener. Models captured by triggers are skipped by the listener
- `trace:triggers install|remove|status` (`--model`, `--sql`) manages the triggers, `trace:doctor` reports missing, outdated and leftover ones

## 0.2.0 - 2026-10-13

### Added

- `#[Traceable(source: ProductSource::class)]` names the source of a model, so `Route::source('products', Product::class)` works as well; passing the Source class stays possible (and is the way to publish one model through several feeds). A model without a source, or one whose source publishes another model, is refused when the route is registered
- `trace:install` asks which side(s) to prepare (Laravel Prompts) when neither `--source` nor `--remote` is given; non-interactive runs still need a flag (`laravel/prompts` is now a direct requirement)
- Several remote sources are documented and covered by tests: named `trace.remote.connections` and `#[Sync(connection: ...)]`

## 0.1.0 - 2026-10-13

Initial release.

### Added

- Source side: `Route::source('products', ProductSource::class)` registers `GET {feed}/snapshot` and `GET {feed}/changes` as class based routes (route cache safe), behind `throttle:trace` and the fail-closed `trace.auth` middleware (constant time bearer token, HTTPS in production, `503` without a token)
- `Source` with an explicit field whitelist; hidden model attributes, the primary key, duplicates and `trace.source.forbidden_fields` are refused
- `#[Traceable]` marker and one synchronous wildcard Eloquent listener, no trait and no observer; force delete and restore are normalised to one log row
- Commit ordered change stream: `trace_streams` row lock + `trace_changes`, fail loudly inside a transaction, best effort (reported) outside of one, fail fast on a second connection
- Keyset paginated snapshot with a stable watermark, state convergence change feed with tombstones, signed opaque cursors, `410 cursor_expired` after retention (`trace:prune`)
- Remote side: `#[Sync]`, `Mapper` (`fields()`, `match()`, `transform()`), `trace_links` instead of a `remote_id` column, `Remote::for(Model::class)->sync()->run()|dispatch()`, `->reconcile()`
- Resumable bootstrap with page checkpoints, catch-up from the watermark, incremental sync where every page commits records, links and cursor together, idempotent soft delete / restore
- Full reconcile with presence marking, a deletion guard (ratio + explicit override) and hard failures on a changed `instance_id`
- `TraceSyncJob` with one active run per connection + feed + model (atomic cache lock), retries for timeouts, 429 and 5xx only
- `SyncStarted`, `SyncCompleted`, `SyncFailed` events, structured logs without record values
- `trace:install`, `trace:make-source`, `trace:make-mapper`, `trace:sync` (`--queue`, `--full`), `trace:doctor`, `trace:prune`
- Enum helpers `is()`, `isNot()`, `in()`, `notIn()`
