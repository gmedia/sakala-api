<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Bucket\BucketAttachmentController;
use App\Http\Controllers\Api\V1\Bucket\BucketController;
use App\Http\Controllers\Api\V1\Database\DatabaseAttachmentController;
use App\Http\Controllers\Api\V1\Database\DatabaseController;
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
use App\Http\Controllers\Api\V1\Project\ProjectMetricsController;
use App\Http\Controllers\Api\V1\Project\ProjectProcessController;
use App\Http\Controllers\Api\V1\Project\SkippedPushController;
use App\Http\Controllers\Api\V1\Runtime\PilotLimitsController;
use App\Http\Controllers\Api\V1\Runtime\RegionController;
use App\Http\Controllers\Api\V1\Schedule\ProjectScheduleController;
use App\Http\Controllers\Api\V1\Schedule\ScheduleRunController;
use App\Http\Controllers\Api\V1\Valkey\ValkeyAttachmentController;
use App\Http\Controllers\Api\V1\Valkey\ValkeyController;
use App\Http\Controllers\Api\V1\Workspace\WorkspaceController;
use App\Http\Controllers\Api\V1\Workspace\WorkspaceInvitationController;
use App\Http\Controllers\Api\V1\Workspace\WorkspaceMemberController;
use App\Http\Middleware\EnsureEmailDeliveryAvailable;
use App\Http\Middleware\EnsureLegalAcceptance;
use App\Http\Middleware\EnsureRuntimeFeatureEnabled;
use App\Support\Runtime\RuntimeFeatures;
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

    // Regions a project may run in
    Route::get('/regions', [RegionController::class, 'index'])
        ->name('api.v1.app.regions.index');

    // Project routes
    Route::get('projects/domain-preview', DomainPreviewController::class)
        ->name('api.v1.app.projects.domain-preview');
    Route::apiResource('projects', ProjectController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', EnsureLegalAcceptance::class);
    Route::post('projects/{project}/inspection', [ProjectController::class, 'inspect'])
        ->middleware(['throttle:inspection', EnsureLegalAcceptance::class])
        ->name('api.v1.app.projects.inspection');
    Route::post('projects/{project}/wake', [ProjectController::class, 'wake'])
        ->name('api.v1.app.projects.wake');
    Route::post('projects/{project}/restart', [ProjectController::class, 'restart'])
        ->middleware(EnsureLegalAcceptance::class)
        ->name('api.v1.app.projects.restart');
    Route::get('projects/{project}/metrics', ProjectMetricsController::class)
        ->name('api.v1.app.projects.metrics');
    Route::get('projects/{project}/skipped-pushes', SkippedPushController::class)
        ->name('api.v1.app.projects.skipped-pushes.index');

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

    // Worker processes of a project (ADR-022); 404 while SAKALA_WORKERS_ENABLED is off.
    Route::scopeBindings()
        ->middleware(EnsureRuntimeFeatureEnabled::class.':'.RuntimeFeatures::WORKERS)
        ->whereUuid(['process'])
        ->group(function (): void {
            Route::get('projects/{project}/processes', [ProjectProcessController::class, 'index'])
                ->name('api.v1.app.projects.processes.index');
            Route::post('projects/{project}/processes', [ProjectProcessController::class, 'store'])
                ->name('api.v1.app.projects.processes.store');
            Route::patch('projects/{project}/processes/{process}', [ProjectProcessController::class, 'update'])
                ->name('api.v1.app.projects.processes.update');
            Route::delete('projects/{project}/processes/{process}', [ProjectProcessController::class, 'destroy'])
                ->name('api.v1.app.projects.processes.destroy');
        });

    // Schedules of a project and their runs (ADR-022); 404 while
    // SAKALA_SCHEDULES_ENABLED is off. Run now starts work like a deployment,
    // so it needs the current legal terms.
    Route::scopeBindings()
        ->middleware(EnsureRuntimeFeatureEnabled::class.':'.RuntimeFeatures::SCHEDULES)
        ->whereUuid(['schedule', 'run'])
        ->group(function (): void {
            Route::get('projects/{project}/schedules', [ProjectScheduleController::class, 'index'])
                ->name('api.v1.app.projects.schedules.index');
            Route::post('projects/{project}/schedules', [ProjectScheduleController::class, 'store'])
                ->name('api.v1.app.projects.schedules.store');
            Route::get('projects/{project}/schedules/{schedule}', [ProjectScheduleController::class, 'show'])
                ->name('api.v1.app.projects.schedules.show');
            Route::patch('projects/{project}/schedules/{schedule}', [ProjectScheduleController::class, 'update'])
                ->name('api.v1.app.projects.schedules.update');
            Route::delete('projects/{project}/schedules/{schedule}', [ProjectScheduleController::class, 'destroy'])
                ->name('api.v1.app.projects.schedules.destroy');
            Route::get('projects/{project}/schedules/{schedule}/runs', [ScheduleRunController::class, 'index'])
                ->name('api.v1.app.projects.schedules.runs.index');
            Route::post('projects/{project}/schedules/{schedule}/runs', [ScheduleRunController::class, 'store'])
                ->middleware(EnsureLegalAcceptance::class)
                ->name('api.v1.app.projects.schedules.runs.store');
            Route::post('projects/{project}/schedules/{schedule}/runs/{run}/cancel', [ScheduleRunController::class, 'cancel'])
                ->name('api.v1.app.projects.schedules.runs.cancel');
            Route::get('projects/{project}/schedules/{schedule}/runs/{run}/logs', [ScheduleRunController::class, 'logs'])
                ->name('api.v1.app.projects.schedules.runs.logs');
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
                Route::post('/rollback', [DeploymentController::class, 'rollback'])
                    ->middleware(EnsureLegalAcceptance::class)
                    ->name('api.v1.app.projects.deployments.rollback');
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

    // Workspace routes
    Route::get('/workspaces', [WorkspaceController::class, 'index'])
        ->name('api.v1.app.workspaces.index');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])
        ->name('api.v1.app.workspaces.store');
    Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show'])
        ->whereUuid('workspace')
        ->name('api.v1.app.workspaces.show');
    Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update'])
        ->whereUuid('workspace')
        ->name('api.v1.app.workspaces.update');
    Route::delete('/workspaces/{workspace}', [WorkspaceController::class, 'destroy'])
        ->whereUuid('workspace')
        ->name('api.v1.app.workspaces.destroy');
    Route::post('/workspaces/{workspace}/leave', [WorkspaceController::class, 'leave'])
        ->whereUuid('workspace')
        ->name('api.v1.app.workspaces.leave');

    Route::scopeBindings()->whereUuid(['workspace', 'member', 'invitation'])->group(function (): void {
        Route::get('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index'])
            ->name('api.v1.app.workspaces.members.index');
        Route::patch('/workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'update'])
            ->name('api.v1.app.workspaces.members.update');
        Route::delete('/workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'destroy'])
            ->name('api.v1.app.workspaces.members.destroy');

        Route::get('/workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'index'])
            ->name('api.v1.app.workspaces.invitations.index');
        Route::post('/workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'store'])
            ->middleware([EnsureEmailDeliveryAvailable::class, 'throttle:workspace-invitations'])
            ->name('api.v1.app.workspaces.invitations.store');
        Route::delete('/workspaces/{workspace}/invitations/{invitation}', [WorkspaceInvitationController::class, 'destroy'])
            ->name('api.v1.app.workspaces.invitations.destroy');
    });

    // Managed databases of a workspace (ADR-020)
    Route::scopeBindings()->whereUuid(['workspace', 'database'])->group(function (): void {
        Route::get('/workspaces/{workspace}/databases', [DatabaseController::class, 'index'])
            ->name('api.v1.app.workspaces.databases.index');
        Route::post('/workspaces/{workspace}/databases', [DatabaseController::class, 'store'])
            ->name('api.v1.app.workspaces.databases.store');
        Route::get('/workspaces/{workspace}/databases/{database}', [DatabaseController::class, 'show'])
            ->name('api.v1.app.workspaces.databases.show');
        Route::patch('/workspaces/{workspace}/databases/{database}', [DatabaseController::class, 'update'])
            ->name('api.v1.app.workspaces.databases.update');
        Route::delete('/workspaces/{workspace}/databases/{database}', [DatabaseController::class, 'destroy'])
            ->name('api.v1.app.workspaces.databases.destroy');
        Route::get('/workspaces/{workspace}/databases/{database}/credentials', [DatabaseController::class, 'credentials'])
            ->name('api.v1.app.workspaces.databases.credentials');
        Route::post('/workspaces/{workspace}/databases/{database}/rotate-password', [DatabaseController::class, 'rotatePassword'])
            ->name('api.v1.app.workspaces.databases.rotate-password');
        Route::post('/workspaces/{workspace}/databases/{database}/extensions', [DatabaseController::class, 'installExtension'])
            ->name('api.v1.app.workspaces.databases.extensions.store');
    });

    // The project is not a child of the database before it is attached, so
    // the controller checks that both belong to the workspace.
    Route::whereUuid(['workspace', 'database', 'project'])->group(function (): void {
        Route::post('/workspaces/{workspace}/databases/{database}/attachments/{project}', [DatabaseAttachmentController::class, 'store'])
            ->name('api.v1.app.workspaces.databases.attachments.store');
        Route::delete('/workspaces/{workspace}/databases/{database}/attachments/{project}', [DatabaseAttachmentController::class, 'destroy'])
            ->name('api.v1.app.workspaces.databases.attachments.destroy');
    });

    // Managed S3 buckets of a workspace (ADR-021). Creating one starts using
    // the service, so it needs the current legal terms like a project.
    Route::scopeBindings()->whereUuid(['workspace', 'bucket'])->group(function (): void {
        Route::get('/workspaces/{workspace}/buckets', [BucketController::class, 'index'])
            ->name('api.v1.app.workspaces.buckets.index');
        Route::post('/workspaces/{workspace}/buckets', [BucketController::class, 'store'])
            ->middleware(EnsureLegalAcceptance::class)
            ->name('api.v1.app.workspaces.buckets.store');
        Route::get('/workspaces/{workspace}/buckets/{bucket}', [BucketController::class, 'show'])
            ->name('api.v1.app.workspaces.buckets.show');
        Route::patch('/workspaces/{workspace}/buckets/{bucket}', [BucketController::class, 'update'])
            ->name('api.v1.app.workspaces.buckets.update');
        Route::delete('/workspaces/{workspace}/buckets/{bucket}', [BucketController::class, 'destroy'])
            ->name('api.v1.app.workspaces.buckets.destroy');
        Route::get('/workspaces/{workspace}/buckets/{bucket}/credentials', [BucketController::class, 'credentials'])
            ->name('api.v1.app.workspaces.buckets.credentials');
        Route::post('/workspaces/{workspace}/buckets/{bucket}/rotate-key', [BucketController::class, 'rotateKey'])
            ->name('api.v1.app.workspaces.buckets.rotate-key');
    });

    // The project is not a child of the bucket before it is attached, so the
    // controller checks that both belong to the workspace.
    Route::whereUuid(['workspace', 'bucket', 'project'])->group(function (): void {
        Route::post('/workspaces/{workspace}/buckets/{bucket}/attachments/{project}', [BucketAttachmentController::class, 'store'])
            ->name('api.v1.app.workspaces.buckets.attachments.store');
        Route::delete('/workspaces/{workspace}/buckets/{bucket}/attachments/{project}', [BucketAttachmentController::class, 'destroy'])
            ->name('api.v1.app.workspaces.buckets.attachments.destroy');
    });

    // Managed Valkey stores of a workspace (ADR-023); 404 while
    // SAKALA_VALKEY_ENABLED is off. Creating one starts using a resource, so
    // it needs the current legal terms like a bucket. The project is not a
    // child of the store before it is attached, so the attachment controller
    // checks that both belong to the workspace.
    Route::middleware(EnsureRuntimeFeatureEnabled::class.':'.RuntimeFeatures::VALKEY)->group(function (): void {
        Route::scopeBindings()->whereUuid(['workspace', 'valkey'])->group(function (): void {
            Route::get('/workspaces/{workspace}/valkeys', [ValkeyController::class, 'index'])
                ->name('api.v1.app.workspaces.valkeys.index');
            Route::post('/workspaces/{workspace}/valkeys', [ValkeyController::class, 'store'])
                ->middleware(EnsureLegalAcceptance::class)
                ->name('api.v1.app.workspaces.valkeys.store');
            Route::get('/workspaces/{workspace}/valkeys/{valkey}', [ValkeyController::class, 'show'])
                ->name('api.v1.app.workspaces.valkeys.show');
            Route::patch('/workspaces/{workspace}/valkeys/{valkey}', [ValkeyController::class, 'update'])
                ->name('api.v1.app.workspaces.valkeys.update');
            Route::delete('/workspaces/{workspace}/valkeys/{valkey}', [ValkeyController::class, 'destroy'])
                ->name('api.v1.app.workspaces.valkeys.destroy');
            Route::get('/workspaces/{workspace}/valkeys/{valkey}/credentials', [ValkeyController::class, 'credentials'])
                ->name('api.v1.app.workspaces.valkeys.credentials');
            Route::post('/workspaces/{workspace}/valkeys/{valkey}/rotate-password', [ValkeyController::class, 'rotatePassword'])
                ->name('api.v1.app.workspaces.valkeys.rotate-password');
        });

        Route::whereUuid(['workspace', 'valkey', 'project'])->group(function (): void {
            Route::post('/workspaces/{workspace}/valkeys/{valkey}/attachments/{project}', [ValkeyAttachmentController::class, 'store'])
                ->name('api.v1.app.workspaces.valkeys.attachments.store');
            Route::delete('/workspaces/{workspace}/valkeys/{valkey}/attachments/{project}', [ValkeyAttachmentController::class, 'destroy'])
                ->name('api.v1.app.workspaces.valkeys.attachments.destroy');
        });
    });

    // Invitations addressed to the caller
    Route::get('/workspace-invitations', [WorkspaceInvitationController::class, 'mine'])
        ->name('api.v1.app.workspace-invitations.index');
    Route::get('/workspace-invitations/{invitation}', [WorkspaceInvitationController::class, 'show'])
        ->whereUuid('invitation')
        ->middleware('throttle:account-security')
        ->name('api.v1.app.workspace-invitations.show');
    Route::post('/workspace-invitations/{invitation}/accept', [WorkspaceInvitationController::class, 'accept'])
        ->whereUuid('invitation')
        ->middleware('throttle:account-security')
        ->name('api.v1.app.workspace-invitations.accept');
    Route::post('/workspace-invitations/{invitation}/decline', [WorkspaceInvitationController::class, 'decline'])
        ->whereUuid('invitation')
        ->middleware('throttle:account-security')
        ->name('api.v1.app.workspace-invitations.decline');

    // Profile routes
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('api.v1.app.profile.update');
    Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])
        ->name('api.v1.app.profile.avatar.destroy');
});
