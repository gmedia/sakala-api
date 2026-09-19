<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Actions\Admin\ChangeAgentNodeLifecycleAction;
use App\Actions\Admin\RequestAgentNodeCleanupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CleanupAgentNodeRequest;
use App\Http\Requests\Api\V1\Admin\DrainAgentNodeRequest;
use App\Http\Requests\Api\V1\Admin\ResumeAgentNodeRequest;
use App\Http\Resources\Api\V1\Admin\AgentNodeControlResource;
use App\Models\AgentNode;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;

final class AgentNodeControlController extends Controller
{
    /**
     * Stop offering workload to a node and ask it to drain.
     *
     * @scramble-return AgentNodeControlResource
     */
    #[HeaderParameter(
        'Idempotency-Key',
        description: 'Unique key used to safely retry the drain request.',
        type: 'string',
        example: '550e8400-e29b-41d4-a716-446655440000',
    )]
    public function drain(
        DrainAgentNodeRequest $request,
        AgentNode $agent,
        ChangeAgentNodeLifecycleAction $action,
    ): JsonResponse {
        $result = $action->drain($agent, $request->user(), $request->toData());

        return (new AgentNodeControlResource($result))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Return a drained node to active duty.
     *
     * @scramble-return AgentNodeControlResource
     */
    #[HeaderParameter(
        'Idempotency-Key',
        description: 'Unique key used to safely retry the resume request.',
        type: 'string',
        example: '550e8400-e29b-41d4-a716-446655440000',
    )]
    public function resume(
        ResumeAgentNodeRequest $request,
        AgentNode $agent,
        ChangeAgentNodeLifecycleAction $action,
    ): JsonResponse {
        $result = $action->resume($agent, $request->user(), $request->toData());

        return (new AgentNodeControlResource($result))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Ask a node to reclaim stale workspaces, images, or routes.
     *
     * @scramble-return AgentNodeControlResource
     */
    #[HeaderParameter(
        'Idempotency-Key',
        description: 'Unique key used to safely retry the cleanup request.',
        type: 'string',
        example: '550e8400-e29b-41d4-a716-446655440000',
    )]
    public function cleanup(
        CleanupAgentNodeRequest $request,
        AgentNode $agent,
        RequestAgentNodeCleanupAction $action,
    ): JsonResponse {
        $result = $action->handle($agent, $request->user(), $request->toData());

        return (new AgentNodeControlResource($result))
            ->response()
            ->setStatusCode(202);
    }
}
