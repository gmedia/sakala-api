<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Valkey;

use App\Actions\Valkey\AttachValkeyAction;
use App\Actions\Valkey\DetachValkeyAction;
use App\Exceptions\Valkey\ProjectValkeyAlreadyAttachedException;
use App\Exceptions\Valkey\ValkeyLockedException;
use App\Exceptions\Valkey\ValkeyNodeMismatchException;
use App\Exceptions\Valkey\ValkeyNotReadyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Valkey\ValkeyAttachmentResource;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Attach a workspace's Valkey store to one of its projects, or detach it.
 * Both take effect at the project's next deployment, whose containers are
 * created on the store's network. `404` while `SAKALA_VALKEY_ENABLED` is off.
 */
final class ValkeyAttachmentController extends Controller
{
    /**
     * Attach the store to the project. `201`, or `200` when it already was. The project must run, or be able to run,
     * on the store's node (`VALKEY_NODE_MISMATCH` otherwise). Its next deployment joins the store's network and
     * receives `REDIS_URL` (without a database number), `REDIS_HOST`, `REDIS_PORT`, and `REDIS_PASSWORD`, except
     * those the project sets itself.
     *
     * @throws ValkeyLockedException
     * @throws ValkeyNotReadyException
     * @throws ProjectValkeyAlreadyAttachedException
     * @throws ValkeyNodeMismatchException
     */
    #[OpenApiResponse(201, 'The store is attached.', type: 'array{data: ValkeyAttachmentResource}')]
    #[OpenApiResponse(200, 'The store already was attached.', type: 'array{data: ValkeyAttachmentResource}')]
    public function store(Request $request, Workspace $workspace, ManagedValkey $valkey, Project $project, AttachValkeyAction $action): JsonResponse
    {
        $this->ensureSameWorkspace($workspace, $valkey, $project);
        Gate::authorize('update', $valkey);
        Gate::authorize('update', $project);

        /** @var User $user */
        $user = $request->user();
        $result = $action->handle($user, $valkey, $project);

        return (new ValkeyAttachmentResource($result['attachment']->load('project')))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    /**
     * Detach the store from the project. The data stays; the project's next deployment no longer joins the store's
     * network or receives its variables. `404` when the store is not attached to the project.
     *
     * @status 204
     */
    public function destroy(Request $request, Workspace $workspace, ManagedValkey $valkey, Project $project, DetachValkeyAction $action): Response
    {
        $this->ensureSameWorkspace($workspace, $valkey, $project);
        Gate::authorize('update', $valkey);
        Gate::authorize('update', $project);

        /** @var User $user */
        $user = $request->user();
        $action->handle($user, $valkey, $project);

        return response()->noContent();
    }

    /**
     * A store or project of another workspace is missing here, like a nested
     * resource. Checked before the policies, so an outsider learns as little
     * as with any unknown ID.
     */
    private function ensureSameWorkspace(Workspace $workspace, ManagedValkey $valkey, Project $project): void
    {
        abort_unless($valkey->workspace_id === $workspace->id && $project->workspace_id === $workspace->id, 404);
    }
}
