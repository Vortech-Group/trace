## Trace (vortech/laravel-trace)

One-way, change-based sync of Eloquent data between two Laravel apps: the source publishes a change feed, the remote pulls it. Activate the `trace-development` skill when working with `#[Traceable]`, `#[Sync]`, `Source`, `Mapper`, `Remote::for()` or any `trace:*` command.

- Source side: mark the model with `#[Traceable]` (no trait, no interface), publish fields through a `Source` class with an explicit `$fields` whitelist, and register it with `Route::source('feed', ...)`.
- Remote side: mark the model with `#[Sync(source: 'feed', mapper: ...)]`; it must use `SoftDeletes`. Trace only ever soft deletes.
- Scaffold with `php artisan trace:make-source` and `trace:make-mapper`; never hand-write the boilerplate.
- Run a sync with `Remote::for(Model::class)->sync()->run()` or `->dispatch()`. `sync()` only describes the operation; `run()` and `dispatch()` are the terminal calls.
- Define `match()` in the mapper whenever the remote table already holds data, otherwise every record becomes a duplicate row. Rehearse with `php artisan trace:sync 'App\Models\X' --dry-run` first.
- Writes that bypass Eloquent events (mass updates, `DB::table()`, raw SQL) are not logged unless the model uses `CaptureMode::Database` (`trace:triggers install`). Write through `DB::transaction()` for atomic logging.
- Never add a `remote_id` column; links live in `trace_links`. Never publish hidden attributes, tokens or the primary key.
- Diagnose problems with `php artisan trace:doctor` before changing code.
