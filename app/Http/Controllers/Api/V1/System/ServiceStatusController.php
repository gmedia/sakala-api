<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\System;

use App\Actions\System\GetServiceStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\System\ServiceStatusResource;

final class ServiceStatusController extends Controller
{
    /**
     * Show the Sakala API service status.
     *
     * This public endpoint confirms that the versioned API is reachable.
     */
    public function __invoke(GetServiceStatusAction $getServiceStatus): ServiceStatusResource
    {
        return ServiceStatusResource::make($getServiceStatus->handle());
    }
}
