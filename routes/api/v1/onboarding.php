<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Onboarding\StoreOnboardingSourceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sakala Onboarding Routes
|--------------------------------------------------------------------------
*/
Route::prefix('onboarding')->middleware('auth:web')->group(function (): void {
    Route::post('source', StoreOnboardingSourceController::class)->name('api.v1.onboarding.source');
});
