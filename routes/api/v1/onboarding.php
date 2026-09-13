<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Onboarding\OnboardingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sakala Onboarding Routes
|--------------------------------------------------------------------------
*/
Route::prefix('onboarding')->middleware('auth:web')->group(function (): void {
    Route::post('source', OnboardingController::class.'@source')->name('api.v1.onboarding.source');
    Route::post('profile', OnboardingController::class.'@profile')->name('api.v1.onboarding.profile');
    Route::post('complete', OnboardingController::class.'@complete')->name('api.v1.onboarding.complete');
});
