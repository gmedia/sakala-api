<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes (Sanctum SPA & OAuth)
|--------------------------------------------------------------------------
|
| API authentication routes. Note: OAuth callbacks are handled via web routes
| in routes/web.php to support session-based CSRF protection.
|
*/
Route::prefix('auth')->group(function (): void {
    Route::get('user', CurrentUserController::class)->middleware('auth:sanctum');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});
