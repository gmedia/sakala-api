<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agent Machine v1 Routes
|--------------------------------------------------------------------------
*/
Route::prefix('agent')->group(function (): void {
    // e.g. Route::post('heartbeat', HeartbeatController::class);
});
