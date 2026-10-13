<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vortech\Trace\Data\SyncResult;
use Vortech\Trace\Facades\Remote;
use Vortech\Trace\Tests\Support\Product;
use Vortech\Trace\Tests\Support\RemoteProduct;
use Vortech\Trace\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Concurrency');

pest()->beforeEach(function (): void {
    $this->bridgeSource();
})->in('Feature');

/** Source writes happen in a transaction: the supported, lossless write path. */
function inTx(\Closure $callback): mixed
{
    return DB::transaction($callback);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeProduct(array $attributes = []): Product
{
    static $n = 0;
    $n++;

    return inTx(fn (): Product => Product::query()->create($attributes + [
        'code' => sprintf('PR-%04d', $n),
        'name' => "Product {$n}",
        'slug' => "Product{$n}-Slug ",
        'is_active' => true,
        'internal_note' => 'never published',
    ]));
}

function syncProducts(): SyncResult
{
    return Remote::for(RemoteProduct::class)->sync()->run();
}

function reconcileProducts(): SyncResult
{
    return Remote::for(RemoteProduct::class)->reconcile()->run();
}

/** Installs the capture triggers of the models that use CaptureMode::Database. */
function installTriggers(): void
{
    test()->artisan('trace:triggers', ['action' => 'install'])->assertSuccessful();
}
