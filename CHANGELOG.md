# Changelog

All notable changes to `laravel-trace` will be documented in this file

## 0.2.0 - 2026-10-13

### Added

- `#[Traceable(source: ClinicSource::class)]` names the source of a model, so `Route::source('clinics', Clinic::class)` works as well; passing the Source class stays possible (and is the way to publish one model through several feeds). A model without a source, or one whose source publishes another model, is refused when the route is registered
- `trace:install` asks which side(s) to prepare (Laravel Prompts) when neither `--source` nor `--remote` is given; non-interactive runs still need a flag (`laravel/prompts` is now a direct requirement)
- Several remote sources are documented and covered by tests: named `trace.remote.connections` and `#[Sync(connection: ...)]`

## 0.1.0 - 2026-10-13

Initial release.

### Added

- Source side: `Route::source('clinics', ClinicSource::class)` registers `GET {feed}/snapshot` and `GET {feed}/changes` as class based routes (route cache safe), behind `throttle:trace` and the fail-closed `trace.auth` middleware (constant time bearer token, HTTPS in production, `503` without a token)
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
