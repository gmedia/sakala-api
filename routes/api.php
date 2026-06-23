<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware('throttle:api')
    ->group(function (): void {
        require __DIR__.'/api/v1/system.php';
        require __DIR__.'/api/v1/auth.php';
        require __DIR__.'/api/v1/app.php';
        require __DIR__.'/api/v1/admin.php';
        require __DIR__.'/api/v1/agent.php';
        require __DIR__.'/api/v1/webhooks.php';
    });
