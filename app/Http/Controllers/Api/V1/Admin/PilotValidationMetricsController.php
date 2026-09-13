<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetPilotValidationMetricsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\PilotValidationMetricsRequest;
use App\Http\Resources\Api\V1\Admin\PilotValidationMetricsResource;

final class PilotValidationMetricsController extends Controller
{
    public function __invoke(
        PilotValidationMetricsRequest $request,
        GetPilotValidationMetricsAction $action,
    ): PilotValidationMetricsResource {
        return new PilotValidationMetricsResource(
            $action->handle($request->toData()),
        );
    }
}
