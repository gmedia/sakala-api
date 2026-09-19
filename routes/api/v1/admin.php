<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\PilotValidationMetricsController;
use App\Http\Controllers\Api\V1\Admin\ProjectControlController;
use App\Http\Controllers\Api\V1\Admin\UsageSignalsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware('auth:web')->group(function (): void {

    // Stop and Suspend Routes
    Route::post('projects/{project}/stop', [ProjectControlController::class, 'stop']);
    Route::post('projects/{project}/suspend', [ProjectControlController::class, 'suspend']);
    Route::post('projects/{project}/reconcile', [ProjectControlController::class, 'reconcile']);

    // Metrics Routes
    Route::get('metrics/pilot-validation', PilotValidationMetricsController::class);

    // Usage & Abuse Signals Routes
    Route::get('signals', UsageSignalsController::class);
});
