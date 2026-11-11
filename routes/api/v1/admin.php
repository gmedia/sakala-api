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
|
| Every route here is for Sakala admins only. `can:admin` answers 403 to any
| other signed-in user before a controller runs; the console hiding its admin
| menu is not the boundary.
|
*/
Route::prefix('admin')->middleware(['auth:web', 'can:admin'])->group(function (): void {

    // Dashboard

    // Projects, with the stop, suspend, unsuspend, and reconcile controls
    Route::post('projects/{project}/stop', [ProjectControlController::class, 'stop']);
    Route::post('projects/{project}/suspend', [ProjectControlController::class, 'suspend']);
    Route::post('projects/{project}/unsuspend', [ProjectControlController::class, 'unsuspend']);
    Route::post('projects/{project}/reconcile', [ProjectControlController::class, 'reconcile']);

    // Users

    // Workspaces and limit profiles

    // Deployments

    // Runtime nodes

    // Feedback and audit

    // Metrics Routes
    Route::get('metrics/pilot-validation', PilotValidationMetricsController::class);

    // Usage & Abuse Signals Routes
    Route::get('signals', UsageSignalsController::class);
});
