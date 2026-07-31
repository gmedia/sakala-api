<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Project\ProjectController;
use Illuminate\Support\Facades\Route;

// ->middleware('auth:sanctum')
/*
|--------------------------------------------------------------------------
| Sakala Console App Routes
|--------------------------------------------------------------------------
*/
Route::prefix('app')->middleware('auth:sanctum')->group(function (): void {
    // Project routes
    Route::prefix('projects')->group(function (): void {
        Route::get('/', [ProjectController::class, 'index'])
            ->name('api.v1.app.projects.index');
        Route::post('/', [ProjectController::class, 'store'])
            ->name('api.v1.app.projects.store');
    });
});
