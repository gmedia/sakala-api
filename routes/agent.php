<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Agent\AgentController;
use App\Http\Middleware\EnsureAgentToken;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\LimitAgentHeartbeatPayload;

/*
|--------------------------------------------------------------------------
| Agent Machine v1 Routes
|--------------------------------------------------------------------------
*/
Route::prefix('agent/v1')->group(function (): void {
    // Admin routes for agent provisioning (Sanctum auth)
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('agents', [AgentController::class, 'index']);
        Route::post('agents', [AgentController::class, 'store']);
        Route::get('agents/{agent}', [AgentController::class, 'show'])->whereUuid('agent');
        Route::post('agents/{agent}/rotate', [AgentController::class, 'rotate'])->whereUuid('agent');
        Route::post('agents/{agent}/revoke', [AgentController::class, 'revoke'])->whereUuid('agent');
    });

    // Machine routes for agent heartbeat (Bearer token auth)
    Route::middleware(EnsureAgentToken::class)->group(function (): void {
        // Response is temporary (for #18 issue)
        Route::post('heartbeat', [AgentController::class, 'heartbeat'])
            ->middleware(LimitAgentHeartbeatPayload::class);
    });
});
