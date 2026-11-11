<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Deployment\DeploymentController;
use App\Http\Controllers\Api\V1\Feedback\FeedbackController;
use App\Http\Controllers\Api\V1\GitHub\GithubInstallationController;
use App\Http\Controllers\Api\V1\GitHub\GithubRepositoryController;
use App\Http\Controllers\Api\V1\Notification\NotificationController;
use App\Http\Controllers\Api\V1\Notification\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\Profile\ProfileController;
use App\Http\Controllers\Api\V1\Project\DomainPreviewController;
use App\Http\Controllers\Api\V1\Project\EnvironmentVariableController;
use App\Http\Controllers\Api\V1\Project\ProjectController;
use App\Http\Controllers\Api\V1\Runtime\PilotLimitsController;
use App\Http\Middleware\EnsureLegalAcceptance;
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
    Route::get('projects/domain-preview', DomainPreviewController::class)
        ->name('api.v1.app.projects.domain-preview');
    Route::apiResource('projects', ProjectController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', EnsureLegalAcceptance::class);
    Route::post('projects/{project}/inspection', [ProjectController::class, 'inspect'])
        ->middleware(['throttle:inspection', EnsureLegalAcceptance::class])
        ->name('api.v1.app.projects.inspection');

    // Environment variable routes
    Route::scopeBindings()->group(function (): void {
        Route::get(
            'projects/{project}/environment-variables',
            [EnvironmentVariableController::class, 'index']
        )->name('api.v1.app.projects.environment-variables.index');

        Route::post(
            'projects/{project}/environment-variables',
            [EnvironmentVariableController::class, 'store']
        )->name('api.v1.app.projects.environment-variables.store');

        Route::patch(
            'projects/{project}/environment-variables/{environmentVariable}',
            [EnvironmentVariableController::class, 'update']
        )->name('api.v1.app.projects.environment-variables.update');

        Route::delete(
            'projects/{project}/environment-variables/{environmentVariable}',
            [EnvironmentVariableController::class, 'destroy']
        )->name('api.v1.app.projects.environment-variables.destroy');

        Route::get(
            'projects/{project}/environment-variables/{environmentVariable}/value',
            [EnvironmentVariableController::class, 'value']
        )->name('api.v1.app.projects.environment-variables.value');
    });

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
            ->only(['store', 'show', 'index'])
            ->middlewareFor('store', EnsureLegalAcceptance::class);

        Route::prefix('projects/{project}/deployments/{deployment}')
            ->group(function (): void {
                Route::get('/events', [DeploymentController::class, 'events']);
                Route::get('/logs', [DeploymentController::class, 'logs']);
                Route::post('/cancel', [DeploymentController::class, 'cancel'])
                    ->name('api.v1.app.projects.deployments.cancel');
            });
    });

    // Notification routes
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('api.v1.app.notifications.index');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('api.v1.app.notifications.unread-count');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('api.v1.app.notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereUuid('notification')
        ->name('api.v1.app.notifications.read');
    Route::get('/notification-preferences', [NotificationPreferenceController::class, 'show'])
        ->name('api.v1.app.notification-preferences.show');
    Route::patch('/notification-preferences', [NotificationPreferenceController::class, 'update'])
        ->name('api.v1.app.notification-preferences.update');

    // Profile routes
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('api.v1.app.profile.update');
    Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])
        ->name('api.v1.app.profile.avatar.destroy');
});
