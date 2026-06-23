<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sakala Console App Routes
|--------------------------------------------------------------------------
*/
Route::prefix('app')->group(function (): void {
    // e.g. Route::apiResource('projects', ProjectController::class);
});
