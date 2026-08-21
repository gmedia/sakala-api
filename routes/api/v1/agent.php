<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Agent\AgentController;
use App\Http\Middleware\EnsureAgentToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agent Machine v1 Routes
|--------------------------------------------------------------------------
*/
Route::prefix('agents')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [AgentController::class, 'index']);
    Route::post('/', [AgentController::class, 'store']);
    Route::get('/{agent}', [AgentController::class, 'show'])->whereUuid('agent');
    Route::post('/{agent}/rotate', [AgentController::class, 'rotate'])->whereUuid('agent');
    Route::post('/{agent}/revoke', [AgentController::class, 'revoke'])->whereUuid('agent');
});

Route::prefix('agent')->middleware(EnsureAgentToken::class)->group(function (): void {
    Route::post('heartbeat', fn () => response()->json(['status' => 'ok']));
});
