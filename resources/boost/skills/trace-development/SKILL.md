---
name: trace-development
description: Build, configure and debug one-way Eloquent data synchronization with vortech/laravel-trace. Activate when working with #[Traceable], #[Sync], Source or Mapper classes, Route::source(), Remote::for(), CaptureMode::Database triggers, the Trace dashboard, or any trace:* artisan command, and when a sync fails with reconcile_required, DeletionGuardException, InstanceMismatchException or AmbiguousMatchException.
---

# Trace development

Trace copies published Eloquent data from a **source** app to a **remote** app, one way, by pulling only what changed. An application plays one or both sides. Delivery is at-least-once with idempotent application.

## Setup

```bash
composer require vortech/laravel-trace
php artisan trace:install --source    # or --remote; without a flag it asks (a flag is required with --no-interaction)
php artisan migrate
```

Env: `TRACE_SOURCE_TOKEN` on the source; `TRACE_REMOTE_URL` (base URL of the source's API prefix) and `TRACE_REMOTE_TOKEN` on the remote. The source signs cursors with `app.key`. Production requires HTTPS.

## Source side

```bash
php artisan trace:make-source ProductSource
```

```php
#[Traceable(source: ProductSource::class)]            // optional: alias: 'product', capture: CaptureMode::Database
class Product extends Model { use SoftDeletes; }

final class ProductSource extends Source
{
    protected string $model = Product::class;
    protected array $fields = ['code', 'name', 'is_active'];   // the whitelist
}

Route::source('products', ProductSource::class);      // or Product::class when the model names its source
```

- `$fields` is the only thing that leaves the app. Hidden attributes, passwords, tokens and the primary key are refused (`source.forbidden_fields`).
- To publish one model through several feeds with different field lists, pass the Source class to `Route::source()`. A model without `source:` (or whose source publishes another model) is refused at route registration.
- Routes created: `GET {feed}/snapshot` (paged, first import and reconcile) and `GET {feed}/changes` (after a cursor).
- Set an `alias` on `#[Traceable]` (and the same on `#[Sync]`) **before the first change is logged**; renaming later strands existing `model_type` rows.

### Capture modes

- Default (events): an Eloquent listener logs `created/updated/deleted/restored`. Mass updates, `DB::table()`, raw SQL and imports are **not** logged; a reconcile repairs them.
- `CaptureMode::Database`: triggers write the change log inside the write's own transaction.

```bash
php artisan trace:triggers install        # also: remove | status | --sql | --model=
```

Re-run `install` after changing the table, key or soft delete column. One capturing model per table. Switching back to events needs `trace:triggers remove --model=...` first. `TRUNCATE` is invisible to triggers, reconcile after it. MySQL needs the `TRIGGER` privilege (with binary logging also `SUPER` or `log_bin_trust_function_creators=1`); use `--sql` for a DBA.

Write inside `DB::transaction()` so the change and its log row commit together. Without a transaction there is a small crash window.

Prune the log on the source with `php artisan trace:prune` (scheduled daily). Keep `source.retention_days` longer than the longest bootstrap and longest pause between syncs.

## Remote side

```bash
php artisan trace:make-mapper ProductMapper
```

```php
#[Sync(source: 'products', mapper: ProductMapper::class)]   // connection: 'logistics' for another source installation
class Product extends Model { use SoftDeletes; }           // SoftDeletes is required

final class ProductMapper extends Mapper
{
    public function fields(): array
    {
        return ['name', 'barcode' => 'ean'];                // remote attribute => local column
    }

    public function match(): array
    {
        return ['code' => 'product_code'];                  // finds an existing local row before it is linked
    }

    public function transform(array $mapped): array        // receives local column names
    {
        return $mapped;
    }
}
```

- **Always define `match()` when the table already holds data.** Without it and without a link, Trace creates a new row (duplicates). Zero hits create, one hit links, several throw `AmbiguousMatchException`. Match columns are set on create only.
- A `null` from the source is data; a *missing* attribute is a schema error, never a silent null.
- `#[Sync]` is not repeatable and `Remote::for()` takes no connection. To receive one table from two installations, declare one model per source.
- Named extra sources live in `config/trace.php` under `remote.connections`.

### Running

```php
Remote::for(Product::class)->sync()->run();                       // SyncResult: created, updated, unchanged, deleted, restored, durationMs, cursor
Remote::for(Product::class)->sync()->onQueue('integrations')->dispatch();   // PendingDispatch, not a SyncResult
Remote::for(Product::class)->reconcile()->run();                  // full state comparison
Remote::for(Product::class)->sync()->dryRun()->run();
```

```bash
php artisan trace:sync 'App\Models\Product' [--queue] [--full] [--dry-run] [--allow-mass-deletion]
```

Schedule `sync()->dispatch()` every few minutes, `reconcile()->dispatch()` weekly. Requires a cache store with atomic locks. One run per connection + feed + model is enforced by a lock.

### Dry run

Do this before the first sync of a non-empty table. It runs the real code in a rolled-back transaction and reports created / matched / updated / deleted / restored counts plus the error a real run would hit. Nothing is written, no event fires, state does not move. It cannot be queued and reads the whole feed in one transaction, so do large tables off peak.

## Safety behavior (do not work around it)

| Guard | Behavior |
|---|---|
| Deletes | Only records Trace linked, soft delete only (`safety.require_soft_deletes`) |
| Deletion guard | More than `max_missing_deletion_ratio` (20%) of at least `min_records_for_ratio_guard` links vanishing throws `DeletionGuardException`; override deliberately with `->allowMassDeletion()` / `--allow-mass-deletion` |
| `instance_id` changed | `InstanceMismatchException`; links of two installations are never mixed |
| Schema hash changed, cursor expired (`410 cursor_expired`) | `reconcile_required`: run a reconcile (`--full`) |
| Partial fetch, timeout, mapping or schema error | No deletion by absence, cursor stays |

Retries with backoff apply to timeouts, `429` and `5xx` only; `401`, `403`, schema, mapping and data errors are not retried.

## HTTP responses from the source

`401` bad token, `503` source token not configured (feed closed), `403` HTTP in production, `404` unknown feed, `422` bad cursor or limit, `410` cursor expired.

## Diagnosing

```bash
php artisan trace:doctor [--offline]
```

Checks token, tables, source and mapper declarations, triggers, columns, connections, lock-capable cache, sync state and (unless `--offline`) the source itself. Run it first when something fails. The trace tables must live on the same connection as the traceable/synced models (`trace.connection`), otherwise `ConnectionMismatchException`.

## Dashboard

Off by default (`TRACE_DASHBOARD_ENABLED=true`), mounted at `/trace`, returns `404` outside `local` until gates exist:

```php
Gate::define('viewTrace', fn (?User $user) => $user?->isAdmin() === true);
Gate::define('manageTrace', fn (?User $user) => $user?->isAdmin() === true);
```

`manageTrace` allows sync, reconcile, dry run, prune and trigger install.

## Events and logs

`SyncStarted`, `SyncCompleted`, `SyncFailed` carry the `TraceSyncRun`. Logs `trace.sync.completed` / `trace.sync.failed` hold counters and ids, never record values.

## Testing

Pest on Orchestra Testbench (SQLite); `tests/Concurrency` needs MySQL/PostgreSQL via `TRACE_TEST_DB`. Run `composer test`, `composer analyse` (PHPStan max, strict rules), `composer lint`.
