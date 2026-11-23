<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Runtime;

use App\Actions\Workspace\CreatePersonalWorkspaceAction;
use App\Http\Requests\Api\V1\Runtime\ShowPilotLimitsRequest;
use App\Http\Resources\Api\V1\Runtime\PilotLimitsResource;
use App\Models\User;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Services\Bucket\BucketQuotaPolicy;
use App\Services\Runtime\PilotRuntimeLimitService;

final class PilotLimitsController
{
    /**
     * Quotas, usage, and runtime maximums of one workspace: the one named by
     * `workspace_id`, or the caller's personal workspace, plus whether this
     * installation offers managed databases (`databases.available`) and
     * buckets (`buckets.available`) right now.
     */
    public function show(
        ShowPilotLimitsRequest $request,
        PilotRuntimeLimitService $limitService,
        CreatePersonalWorkspaceAction $personalWorkspace,
        AgentNodeSchedulerService $scheduler,
        BucketQuotaPolicy $bucketQuotas,
    ): PilotLimitsResource {
        /** @var User $user */
        $user = $request->user();

        $workspace = $request->requestedWorkspace() ?? $personalWorkspace->handle($user);

        return PilotLimitsResource::make($limitService->getPilotQuotaLimits($workspace))
            ->withDatabasesAvailable($scheduler->databasesAvailable())
            ->withBuckets($scheduler->bucketsAvailable(), $bucketQuotas->defaultQuotaMb());
    }
}
