<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Deployment\DeploymentController;
use App\Http\Controllers\Api\V1\Feedback\FeedbackController;
use App\Http\Controllers\Api\V1\GitHub\GithubInstallationController;
use App\Http\Controllers\Api\V1\GitHub\GithubRepositoryController;
use App\Http\Controllers\Api\V1\Project\ProjectController;
use App\Http\Controllers\Api\V1\Runtime\PilotLimitsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sakala Console App Routes
|--------------------------------------------------------------------------
*/

Route::prefix('app')->middleware('auth:web')->group(function (): void {
    // Feedback routes
    Route::post('/feedback', [FeedbackController::class, 'store'])
        ->middleware('throttle:feedback')
        ->name('api.v1.app.feedback.store');

    // Pilot runtime and quota limits
    Route::get('/pilot-limits', [PilotLimitsController::class, 'show'])
        ->name('api.v1.app.pilot-limits.show');

    // Project routes
    Route::apiResource('projects', ProjectController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

    // GitHub routes
    Route::prefix('github')->group(function (): void {
        Route::get('/installations', [GithubInstallationController::class, 'index'])->name('api.v1.app.github.installations.index');
        Route::get('/installations/{installation}/repositories', [GithubInstallationController::class, 'repositories'])->name('api.v1.app.github.installations.repositories.index');
        Route::get('/installations/{installation}/repositories/{repositoryId}/branches', [GithubInstallationController::class, 'branches'])->whereNumber('repositoryId')->name('api.v1.app.github.installations.repositories.branches.index');
        Route::post('/repositories/validate', [GithubRepositoryController::class, 'validate'])
            ->name('api.v1.app.github.repositories.validate');
        Route::get('/repositories/branches', [GithubRepositoryController::class, 'branches'])
            ->name('api.v1.app.github.repositories.branches');
    });

    // Deployment routes
    Route::scopeBindings()->group(function (): void {
        Route::apiResource('projects.deployments', DeploymentController::class)
            ->only(['store', 'show', 'index']);

        Route::prefix('projects/{project}/deployments/{deployment}')
            ->group(function (): void {
                Route::get('/events', [DeploymentController::class, 'events']);
                Route::get('/logs', [DeploymentController::class, 'logs']);
            });
    });
});
