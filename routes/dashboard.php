<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vortech\Trace\Dashboard\Http\Controllers\Api\DashboardApiController;
use Vortech\Trace\Dashboard\Http\Controllers\DashboardController;
use Vortech\Trace\Support\TraceConfig;

Route::middleware([...TraceConfig::dashboardMiddleware(), 'trace.dashboard'])
    ->prefix(TraceConfig::dashboardPath())
    ->name('trace.dashboard.')
    ->group(function (): void {
        Route::prefix('api')->name('api.')->group(function (): void {
            Route::get('remote', [DashboardApiController::class, 'remote'])->name('remote');
            Route::get('runs', [DashboardApiController::class, 'runs'])->name('runs');
            Route::get('source', [DashboardApiController::class, 'source'])->name('source');
            Route::get('health', [DashboardApiController::class, 'health'])->name('health');

            Route::middleware('trace.dashboard:manageTrace')->group(function (): void {
                Route::post('remote/sync', [DashboardApiController::class, 'sync'])->name('sync');
                Route::post('remote/reconcile', [DashboardApiController::class, 'reconcile'])->name('reconcile');
                Route::post('source/prune', [DashboardApiController::class, 'prune'])->name('prune');
                Route::post('source/triggers', [DashboardApiController::class, 'triggers'])->name('triggers');
            });
        });

        Route::get('/{page?}', DashboardController::class)
            ->where('page', 'remote|runs|source|health')
            ->name('app');
    });
