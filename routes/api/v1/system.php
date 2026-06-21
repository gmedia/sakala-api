<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\System\ServiceStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/', ServiceStatusController::class)->name('api.v1.status');
