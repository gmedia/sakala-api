<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes (Sanctum SPA & OAuth)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function (): void {
    Route::post('login', LoginController::class)
        ->middleware('throttle:login')
        ->name('api.v1.auth.login');

    Route::middleware('auth:web')->group(function (): void {
        Route::get('user', CurrentUserController::class)->name('api.v1.auth.user');
        Route::post('logout', LogoutController::class)->name('api.v1.auth.logout');
    });
});
