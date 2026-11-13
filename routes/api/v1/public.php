<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PublicProject\ProjectWakeController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
|
| Reached without credentials from pages Sakala serves on project domains.
| Never stateful: no session, no cookie, no CSRF token, whatever the origin.
| Project domains must never be added to SANCTUM_STATEFUL_DOMAINS.
|
*/

Route::prefix('public')
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->group(function (): void {
        Route::post('projects/{slug}/wake', ProjectWakeController::class)
            ->where('slug', '[a-z0-9-]{1,80}')
            ->middleware('throttle:project-wake')
            ->name('api.v1.public.projects.wake');
    });
