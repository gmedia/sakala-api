<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\GithubCallbackController;
use App\Http\Controllers\Auth\GithubRedirectController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')
    ->middleware('throttle:oauth')
    ->group(function (): void {
        Route::get('github/redirect', GithubRedirectController::class)->name('auth.github.redirect');
        Route::get('github/callback', GithubCallbackController::class)->name('auth.github.callback');
    });
