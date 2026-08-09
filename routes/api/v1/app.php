<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Project\ProjectController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sakala Console App Routes
|--------------------------------------------------------------------------
*/
Route::prefix('app')->middleware('auth:web')->group(function (): void {
    Route::apiResource('projects', ProjectController::class)->only([
        'index',
        'store',
        'show',
        'update',
        'destroy',
    ]);
});
