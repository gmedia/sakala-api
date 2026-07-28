<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Project\ProjectController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sakala Console App Routes
|--------------------------------------------------------------------------
*/
Route::prefix('app')->middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('projects', ProjectController::class)->except(['show']);
    Route::get('projects/{project}', [ProjectController::class, 'show'])->withTrashed();
});
