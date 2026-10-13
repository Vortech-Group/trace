<?php

declare(strict_types=1);

use Vortech\Trace\Enums\ChangeType;
use Vortech\Trace\Enums\CursorType;
use Vortech\Trace\Enums\Operation;
use Vortech\Trace\Enums\RunStatus;
use Vortech\Trace\Enums\SyncMode;
use Vortech\Trace\Enums\SyncStatus;

it('compares enum cases', function (): void {
    expect(Operation::Upsert->is(Operation::Upsert))->toBeTrue()
        ->and(Operation::Upsert->is(Operation::Delete))->toBeFalse()
        ->and(Operation::Upsert->isNot(Operation::Delete))->toBeTrue()
        ->and(Operation::Delete->isNot(Operation::Delete))->toBeFalse();
});

it('checks membership of a set of cases', function (): void {
    expect(SyncStatus::Failed->in(SyncStatus::Failed, SyncStatus::ReconcileRequired))->toBeTrue()
        ->and(SyncStatus::Ready->in(SyncStatus::Failed))->toBeFalse()
        ->and(SyncStatus::Ready->notIn(SyncStatus::Failed))->toBeTrue()
        ->and(SyncStatus::Ready->notIn(SyncStatus::Ready, SyncStatus::New))->toBeFalse();
});

it('maps the change log type to the protocol operation', function (): void {
    expect(ChangeType::Changed->operation())->toBe(Operation::Upsert)
        ->and(ChangeType::Deleted->operation())->toBe(Operation::Delete);
});

it('knows which statuses and modes are special', function (): void {
    expect(SyncStatus::New->needsBootstrap())->toBeTrue()
        ->and(SyncStatus::Bootstrapping->needsBootstrap())->toBeTrue()
        ->and(SyncStatus::Ready->needsBootstrap())->toBeFalse()
        ->and(RunStatus::Running->isFinished())->toBeFalse()
        ->and(RunStatus::Failed->isFinished())->toBeTrue()
        ->and(SyncMode::Bootstrap->isFullTraversal())->toBeTrue()
        ->and(SyncMode::Reconcile->isFullTraversal())->toBeTrue()
        ->and(SyncMode::Incremental->isFullTraversal())->toBeFalse()
        ->and(CursorType::Snapshot->isNot(CursorType::Changes))->toBeTrue();
});
