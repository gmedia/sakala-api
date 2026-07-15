<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| These routes are for OAuth callbacks that require session handling.
| API routes are defined in routes/api.php.
|
*/

Route::middleware('web')->group(function (): void {
    Route::get('/auth/github/callback', [AuthController::class, 'handleGitHubCallback'])
        ->name('auth.github.callback');

    Route::get('/auth/github/redirect', [AuthController::class, 'redirectToGitHub'])
        ->name('auth.github.redirect');
});
