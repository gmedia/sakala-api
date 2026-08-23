<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\GithubCallbackController;
use App\Http\Controllers\Auth\GithubInstallationConfigureController;
use App\Http\Controllers\Auth\GithubInstallationRedirectController;
use App\Http\Controllers\Auth\GithubInstallationSetupController;
use App\Http\Controllers\Auth\GithubRedirectController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')
    ->middleware('throttle:oauth')
    ->group(function (): void {
        Route::get('github/redirect', GithubRedirectController::class)->name('auth.github.redirect');
        Route::get('github/callback', GithubCallbackController::class)->name('auth.github.callback');
        Route::middleware('auth:web')->group(function (): void {
            Route::get('github/install', GithubInstallationRedirectController::class)->name('auth.github.install');
            Route::get('github/installations/{installation}/configure', GithubInstallationConfigureController::class)->name('auth.github.installations.configure');
            Route::get('github/setup', GithubInstallationSetupController::class)->name('auth.github.setup');
        });
    });
