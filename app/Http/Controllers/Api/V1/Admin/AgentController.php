<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetAdminAgentCollectionAction;
use App\Actions\Admin\GetAgentCommandCollectionAction;
use App\Actions\Admin\ManageAgentNodeTokenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminReasonRequest;
use App\Http\Requests\Api\V1\Admin\IndexAdminAgentRequest;
use App\Http\Requests\Api\V1\Admin\IndexAgentCommandRequest;
use App\Http\Requests\Api\V1\Admin\StoreAgentNodeRequest;
use App\Http\Resources\Api\V1\Admin\AdminAgentCommandResource;
use App\Http\Resources\Api\V1\Admin\AdminAgentResource;
use App\Http\Resources\Api\V1\Admin\ProvisionedAgentResource;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\User;
use App\Support\Admin\AgentCommandColumns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AgentController extends Controller
{
    /** Newest reports returned on a command detail. */
    public const COMMAND_REPORTS = 200;

    /**
     * Runtime nodes with status, last heartbeat, agent version, capabilities, and workload counts.
     */
    public function index(IndexAdminAgentRequest $request, GetAdminAgentCollectionAction $action): AnonymousResourceCollection
    {
        return AdminAgentResource::collection($action->handle($request->toData()));
    }

    /**
     * Register a runtime node and return its token once.
     *
     * Same effect as `artisan agent:provision`. Put `agent.agent_id` and `token` into the agent's `SAKALA_AGENT_ID` and
     * `SAKALA_AGENT_TOKEN`. The response is not cached; the token cannot be read again.
     *
     * @status 201
     */
    public function store(StoreAgentNodeRequest $request, ManageAgentNodeTokenAction $action): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        return (new ProvisionedAgentResource($action->provision($admin, $request->toData(), $request->reason())))
            ->response()
            ->setStatusCode(201)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * A runtime node.
     */
    public function show(AgentNode $agent): AdminAgentResource
    {
        $agent = GetAdminAgentCollectionAction::withWorkload(AgentNode::query())->findOrFail($agent->id);

        return new AdminAgentResource($agent);
    }

    /**
     * Issue a new token for a node and return it once. The old token stops working immediately.
     */
    public function rotate(AdminReasonRequest $request, AgentNode $agent, ManageAgentNodeTokenAction $action): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        return (new ProvisionedAgentResource($action->rotate($admin, $agent, $request->reason())))
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Revoke a node's token. The node can no longer authenticate or receive commands. Repeating it changes nothing.
     */
    public function revoke(AdminReasonRequest $request, AgentNode $agent, ManageAgentNodeTokenAction $action): AdminAgentResource
    {
        /** @var User $admin */
        $admin = $request->user();

        return new AdminAgentResource($action->revoke($admin, $agent, $request->reason()));
    }

    /**
     * Commands a node received, newest first.
     */
    public function commands(IndexAgentCommandRequest $request, AgentNode $agent, GetAgentCommandCollectionAction $action): AnonymousResourceCollection
    {
        return AdminAgentCommandResource::collection($action->handle($agent, $request->toData()));
    }

    /**
     * One command with the events and logs the node reported for it (up to the 200 newest).
     *
     * Output of a `DeployProject` command is stored as deployment logs: read it through
     * `GET admin/deployments/{deployment}/logs` with the command's `deployment_id`.
     */
    public function command(AgentNode $agent, string $command): AdminAgentCommandResource
    {
        $model = AgentCommand::query()
            ->select(AgentCommandColumns::SAFE)
            ->where('agent_node_id', $agent->id)
            ->with([
                'project' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'slug', 'deleted_at']),
                'reports' => fn ($query) => $query->orderByDesc('sequence')->limit(self::COMMAND_REPORTS),
            ])
            ->withCount('reports')
            ->findOrFail($command);

        $model->setRelation('reports', $model->reports->sortBy('sequence')->values());

        return new AdminAgentCommandResource($model);
    }
}
