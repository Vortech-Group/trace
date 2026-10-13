<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the indexes the retention and the dashboard read', function (): void {
    expect(Schema::hasIndex('trace_changes', ['stream_id', 'created_at']))->toBeTrue()
        ->and(Schema::hasIndex('trace_changes', ['stream_id']))->toBeFalse()
        ->and(Schema::hasIndex('trace_changes', ['stream_id', 'version'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('trace_sync_runs', ['created_at']))->toBeTrue();
});

it('reads the change log of a stream by time through the index', function (): void {
    $plan = collect(DB::select('explain query plan select max(version) from trace_changes where stream_id = ? and created_at < ?', [1, now()]))
        ->map(fn (object $row): string => $row->detail)
        ->implode(' ');

    expect($plan)->toContain('trace_changes_stream_id_created_at_index');
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'EXPLAIN QUERY PLAN is SQLite only');

it('prunes and lists the sync runs by time through the index', function (): void {
    $prune = collect(DB::select('explain query plan delete from trace_sync_runs where created_at < ?', [now()]))->map(fn (object $row): string => $row->detail)->implode(' ');
    $list = collect(DB::select('explain query plan select * from trace_sync_runs order by created_at desc limit 25'))->map(fn (object $row): string => $row->detail)->implode(' ');

    expect($prune)->toContain('trace_sync_runs_created_at_index')
        ->and($list)->toContain('trace_sync_runs_created_at_index')->not->toContain('TEMP B-TREE');
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'EXPLAIN QUERY PLAN is SQLite only');
