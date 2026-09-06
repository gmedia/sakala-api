<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\ProjectControlController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware('auth:web')->group(function (): void {
    // e.g. Route::get('metrics', AdminMetricsController::class);
    Route::post('projects/{project}/stop', [ProjectControlController::class, 'stop']);
    Route::post('projects/{project}/suspend', [ProjectControlController::class, 'suspend']);
});
