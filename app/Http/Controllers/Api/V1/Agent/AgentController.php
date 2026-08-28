<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Actions\Agent\HeartbeatAgentAction;
use App\Actions\Agent\ProvisionAgentAction;
use App\Actions\Agent\RevokeAgentAction;
use App\Actions\Agent\RotateAgentTokenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Agent\AgentHeartbeatRequest;
use App\Http\Requests\Api\V1\Agent\RevokeAgentRequest;
use App\Http\Requests\Api\V1\Agent\RotateAgentTokenRequest;
use App\Http\Requests\Api\V1\Agent\StoreAgentRequest;
use App\Http\Resources\Api\V1\Agent\AgentHeartbeatResource;
use App\Http\Resources\Api\V1\Agent\AgentResource;
use App\Models\AgentNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class AgentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AgentNode::class);

        return AgentResource::collection(
            AgentNode::query()->latest()->get()
        );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @scramble-return AgentResource
     */
    public function store(
        StoreAgentRequest $request,
        ProvisionAgentAction $provisionAgent
    ): JsonResponse {
        $result = $provisionAgent->handle($request->user(), $request->toData());

        return (new AgentResource($result['agent']))
            ->additional(['token' => $result['token']])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     *
     * @scramble-return AgentResource
     */
    public function show(AgentNode $agent): AgentResource
    {
        Gate::authorize('view', $agent);

        return AgentResource::make($agent);
    }

    /**
     * Rotate the agent's token.
     *
     * @scramble-return AgentResource
     */
    public function rotate(
        RotateAgentTokenRequest $request,
        RotateAgentTokenAction $rotateAgentToken,
        AgentNode $agent
    ): AgentResource {
        Gate::authorize('update', $agent);

        $newToken = $rotateAgentToken->handle($agent);

        return AgentResource::make($agent)->additional([
            'token' => $newToken,
        ]);
    }

    /**
     * Revoke the specified resource.
     *
     * @scramble-return \Illuminate\Http\Response
     */
    public function revoke(
        RevokeAgentRequest $request,
        RevokeAgentAction $revokeAgent,
        AgentNode $agent
    ): Response {
        Gate::authorize('delete', $agent);

        $revokeAgent->handle($agent);

        return response()->noContent();
    }

    public function heartbeat(
        AgentHeartbeatRequest $request,
        HeartbeatAgentAction $heartbeatAgent,
        AgentNode $agent
    ): AgentHeartbeatResource {
        $agent = $request->input('agent');

        $agent = $heartbeatAgent->handle(
            agent: $agent,
            data: $request->toData()
        );

        return AgentHeartbeatResource::make($agent);
    }
}
