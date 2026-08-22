<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Webhooks\GithubWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
*/
Route::prefix('webhooks')->group(function (): void {
    Route::post('github', GithubWebhookController::class)->name('api.v1.webhooks.github');
});
