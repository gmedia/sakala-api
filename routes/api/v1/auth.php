<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes (Sanctum SPA & OAuth)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function (): void {
    // e.g. Route::get('user', CurrentUserController::class);
});
