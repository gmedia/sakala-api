<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthOptionsController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\EmailChangeController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationNotificationController;
use App\Http\Middleware\EnsureEmailDeliveryAvailable;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes (Sanctum SPA & OAuth)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function (): void {
    Route::get('options', AuthOptionsController::class)->name('api.v1.auth.options');

    Route::post('register', RegisterController::class)
        ->middleware(['throttle:register', EnsureEmailDeliveryAvailable::class])
        ->name('api.v1.auth.register');

    Route::post('email/verification-notification', ResendVerificationNotificationController::class)
        ->middleware(['throttle:email-verification', EnsureEmailDeliveryAvailable::class])
        ->name('api.v1.auth.email.verification-notification');

    Route::post('login', LoginController::class)
        ->middleware('throttle:login')
        ->name('api.v1.auth.login');

    Route::post('forgot-password', [PasswordController::class, 'forgot'])
        ->middleware(['throttle:password-reset', EnsureEmailDeliveryAvailable::class])
        ->name('api.v1.auth.password.forgot');

    Route::post('reset-password', [PasswordController::class, 'reset'])
        ->middleware('throttle:password-reset')
        ->name('api.v1.auth.password.reset');

    Route::middleware('auth:web')->group(function (): void {
        Route::get('user', CurrentUserController::class)->name('api.v1.auth.user');
        Route::post('logout', LogoutController::class)->name('api.v1.auth.logout');

        Route::put('password', [PasswordController::class, 'update'])
            ->middleware('throttle:account-security')
            ->name('api.v1.auth.password.update');

        Route::post('email/change', EmailChangeController::class)
            ->middleware(['throttle:account-security', EnsureEmailDeliveryAvailable::class])
            ->name('api.v1.auth.email.change');
    });
});
