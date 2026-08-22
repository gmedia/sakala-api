<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Runtime;

use App\Http\Resources\Api\V1\Runtime\PilotLimitsResource;
use App\Models\User;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Http\Request;

final class PilotLimitsController
{
    public function show(Request $request, PilotRuntimeLimitService $limitService): PilotLimitsResource
    {
        /** @var User $user */
        $user = $request->user();

        $quotaLimits = $limitService->getPilotQuotaLimits($user);

        return PilotLimitsResource::make($quotaLimits);
    }
}
