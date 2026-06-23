<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
*/
Route::prefix('webhooks')->group(function (): void {
    // e.g. Route::post('github', GitHubWebhookController::class);
});
