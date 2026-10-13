<?php

declare(strict_types=1);

use Vortech\Trace\Exceptions\DeletionGuardException;
use Vortech\Trace\Remote\DeletionGuard;

beforeEach(function (): void {
    config(['trace.safety.max_missing_deletion_ratio' => 0.20, 'trace.safety.min_records_for_ratio_guard' => 100]);
});

it('lets a small share of vanished records through', function (): void {
    new DeletionGuard()->check(1000, 200, false);

    expect(true)->toBeTrue();
});

it('refuses a large share', function (): void {
    expect(fn () => new DeletionGuard()->check(1000, 201, false))->toThrow(DeletionGuardException::class);
});

it('does not guard small data sets', function (): void {
    new DeletionGuard()->check(99, 99, false);

    expect(true)->toBeTrue();
});

it('accepts an explicit override', function (): void {
    new DeletionGuard()->check(1000, 900, true);

    expect(true)->toBeTrue();
});
