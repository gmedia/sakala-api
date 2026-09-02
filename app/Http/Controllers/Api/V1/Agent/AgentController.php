<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Actions\Agent\ClaimAgentCommandAction;
use App\Actions\Agent\CompleteAgentCommandAction;
use App\Actions\Agent\FailAgentCommandAction;
use App\Actions\Agent\HeartbeatAgentAction;
use App\Actions\Agent\PollAgentCommandsAction;
use App\Actions\Agent\ProvisionAgentAction;
use App\Actions\Agent\RevokeAgentAction;
use App\Actions\Agent\RotateAgentTokenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Agent\AgentHeartbeatRequest;
use App\Http\Requests\Api\V1\Agent\ClaimAgentCommandRequest;
use App\Http\Requests\Api\V1\Agent\CompleteAgentCommandRequest;
use App\Http\Requests\Api\V1\Agent\FailAgentCommandRequest;
use App\Http\Requests\Api\V1\Agent\RevokeAgentRequest;
use App\Http\Requests\Api\V1\Agent\RotateAgentTokenRequest;
use App\Http\Requests\Api\V1\Agent\StoreAgentRequest;
use App\Http\Resources\Api\V1\Agent\AgentCommandResource;
use App\Http\Resources\Api\V1\Agent\AgentHeartbeatResource;
use App\Http\Resources\Api\V1\Agent\AgentResource;
use App\Models\AgentNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

    /**
     * Poll eligible pending commands for the authenticated agent node.
     *
     * @scramble-return AgentCommandResource[]
     */
    public function pollCommands(
        PollAgentCommandsAction $pollAgentCommands
    ): AnonymousResourceCollection {
        /** @var AgentNode $agent */
        $agent = request()->input('agent');

        $commands = $pollAgentCommands->handle($agent);

        return AgentCommandResource::collection($commands);
    }

    /**
     * Atomically claim a pending command for execution.
     *
     * @scramble-return AgentCommandResource
     */
    public function claimCommand(
        ClaimAgentCommandRequest $request,
        ClaimAgentCommandAction $claimAgentCommand,
        string $command
    ): JsonResponse {
        /** @var AgentNode $agent */
        $agent = request()->input('agent');

        $claimed = $claimAgentCommand->handle($agent, $command);

        if ($claimed === null) {
            return response()->json([
                'status' => 'conflict',
            ], 409);
        }

        return (new AgentCommandResource($claimed))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Mark a claimed or running command as succeeded.
     *
     * @scramble-return \Illuminate\Http\Response
     */
    public function completeCommand(
        CompleteAgentCommandRequest $request,
        CompleteAgentCommandAction $completeAgentCommand,
        string $command
    ): Response|JsonResponse {
        /** @var AgentNode $agent */
        $agent = request()->input('agent');

        try {
            $completeAgentCommand->handle(
                agent: $agent,
                commandId: $command,
                result: $request->result(),
            );
        } catch (ConflictHttpException $e) {
            return response()->json([
                'status' => 'conflict',
            ], 409);
        }

        return response()->noContent();
    }

    /**
     * Mark a claimed or running command as failed.
     *
     * @scramble-return \Illuminate\Http\Response
     */
    public function failCommand(
        FailAgentCommandRequest $request,
        FailAgentCommandAction $failAgentCommand,
        string $command
    ): Response|JsonResponse {
        /** @var AgentNode $agent */
        $agent = request()->input('agent');

        try {
            $failAgentCommand->handle(
                agent: $agent,
                commandId: $command,
                errorCode: $request->error_code(),
                errorMessage: $request->error_message(),
            );
        } catch (ConflictHttpException $e) {
            return response()->json([
                'status' => 'conflict',
            ], 409);
        }

        return response()->noContent();
    }
}
