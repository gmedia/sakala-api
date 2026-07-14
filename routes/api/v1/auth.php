<?php

declare(strict_types=1);

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
    // e.g. Route::get('user', CurrentUserController::class);
    // Future: email/password login, password reset, etc.
});
