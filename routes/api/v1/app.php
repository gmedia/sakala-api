<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\GitHub\GithubRepositoryController;
use App\Http\Controllers\Api\V1\Project\ProjectController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sakala Console App Routes
|--------------------------------------------------------------------------
*/

// pu

Route::prefix('app')->middleware('auth:web')->group(function (): void {
    // Project routes
    Route::apiResource('projects', ProjectController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::prefix('github')->group(function (): void {
        Route::get('/repositories', [GithubRepositoryController::class, 'index'])
            ->name('api.v1.app.github.repositories.index');
        Route::post('/repositories/validate', [GithubRepositoryController::class, 'validate'])
            ->name('api.v1.app.github.repositories.validate');
        Route::get('/repositories/count', [GithubRepositoryController::class, 'count'])
            ->name('api.v1.app.github.repositories.count');
        Route::get('/repositories/branches', [GithubRepositoryController::class, 'branches'])
            ->name('api.v1.app.github.repositories.branches');
    });
});
