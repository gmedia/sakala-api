<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetUsageSignalsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UsageSignalsRequest;
use App\Http\Resources\Api\V1\Admin\UsageSignalsResource;

final class UsageSignalsController extends Controller
{
    public function __invoke(
        UsageSignalsRequest $request,
        GetUsageSignalsAction $action,
    ): UsageSignalsResource {
        return new UsageSignalsResource(
            $action->handle($request->toData()),
        );
    }
}
