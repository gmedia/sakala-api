<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Edge\EdgeRouteController;
use App\Http\Middleware\EnsureEdgeToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sakala Edge v1 Routes
|--------------------------------------------------------------------------
|
| Read-only machine routes for Sakala Edge (ADR-019), with their own bearer
| token (artisan edge:token). Contract: docs/EDGE_API.md. Throttled before
| authentication, per client address, so unauthenticated floods are limited too.
*/
Route::prefix('edge/v1')
    ->middleware(['throttle:edge', EnsureEdgeToken::class])
    ->group(function (): void {
        Route::get('routes', [EdgeRouteController::class, 'index'])->name('api.edge.v1.routes.index');
    });
