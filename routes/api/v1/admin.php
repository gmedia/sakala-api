<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AgentController;
use App\Http\Controllers\Api\V1\Admin\AuditEventController;
use App\Http\Controllers\Api\V1\Admin\DeploymentController;
use App\Http\Controllers\Api\V1\Admin\FeedbackController;
use App\Http\Controllers\Api\V1\Admin\LimitProfileController;
use App\Http\Controllers\Api\V1\Admin\OverviewController;
use App\Http\Controllers\Api\V1\Admin\PilotValidationMetricsController;
use App\Http\Controllers\Api\V1\Admin\ProjectControlController;
use App\Http\Controllers\Api\V1\Admin\ProjectController;
use App\Http\Controllers\Api\V1\Admin\UsageSignalsController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\WorkspaceController;
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
    Route::get('overview', OverviewController::class);

    // Projects, with the stop, suspend, unsuspend, and reconcile controls
    Route::get('projects', [ProjectController::class, 'index']);
    Route::get('projects/{project}', [ProjectController::class, 'show'])->whereUuid('project');
    Route::post('projects/{project}/stop', [ProjectControlController::class, 'stop']);
    Route::post('projects/{project}/suspend', [ProjectControlController::class, 'suspend']);
    Route::post('projects/{project}/unsuspend', [ProjectControlController::class, 'unsuspend']);
    Route::post('projects/{project}/reconcile', [ProjectControlController::class, 'reconcile']);

    // Users
    Route::get('users', [UserController::class, 'index']);
    Route::get('users/{user}', [UserController::class, 'show'])->whereNumber('user');
    Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->whereNumber('user');
    Route::patch('users/{user}/team-limit', [UserController::class, 'updateTeamLimit'])->whereNumber('user');
    Route::put('users/{user}/limit-profile', [UserController::class, 'assignLimitProfile'])->whereNumber('user');

    // Workspaces and limit profiles
    Route::get('workspaces', [WorkspaceController::class, 'index']);
    Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show'])->whereUuid('workspace');
    Route::put('workspaces/{workspace}/limit-profile', [WorkspaceController::class, 'assignLimitProfile'])->whereUuid('workspace');
    Route::get('limit-profiles', [LimitProfileController::class, 'index']);
    Route::post('limit-profiles', [LimitProfileController::class, 'store']);
    Route::get('limit-profiles/{limitProfile}', [LimitProfileController::class, 'show'])->whereUuid('limitProfile');
    Route::patch('limit-profiles/{limitProfile}', [LimitProfileController::class, 'update'])->whereUuid('limitProfile');
    Route::delete('limit-profiles/{limitProfile}', [LimitProfileController::class, 'destroy'])->whereUuid('limitProfile');

    // Deployments
    Route::get('deployments/failures', [DeploymentController::class, 'failures']);
    Route::get('deployments/failure-summary', [DeploymentController::class, 'failureSummary']);
    Route::get('deployments/{deployment}', [DeploymentController::class, 'show'])->whereUuid('deployment');
    Route::get('deployments/{deployment}/events', [DeploymentController::class, 'events'])->whereUuid('deployment');
    Route::get('deployments/{deployment}/logs', [DeploymentController::class, 'logs'])->whereUuid('deployment');

    // Runtime nodes
    Route::get('agents', [AgentController::class, 'index']);
    Route::post('agents', [AgentController::class, 'store']);
    Route::get('agents/{agent}', [AgentController::class, 'show'])->whereUuid('agent');
    Route::post('agents/{agent}/rotate', [AgentController::class, 'rotate'])->whereUuid('agent');
    Route::post('agents/{agent}/revoke', [AgentController::class, 'revoke'])->whereUuid('agent');
    Route::get('agents/{agent}/commands', [AgentController::class, 'commands'])->whereUuid('agent');
    Route::get('agents/{agent}/commands/{command}', [AgentController::class, 'command'])->whereUuid(['agent', 'command']);

    // Feedback and audit
    Route::get('feedback', [FeedbackController::class, 'index']);
    Route::patch('feedback/{feedback}', [FeedbackController::class, 'update'])->whereUuid('feedback');
    Route::get('audit-events', AuditEventController::class);

    // Metrics Routes
    Route::get('metrics/pilot-validation', PilotValidationMetricsController::class);

    // Usage & Abuse Signals Routes
    Route::get('signals', UsageSignalsController::class);
});
