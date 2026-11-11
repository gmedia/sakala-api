<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workspace;

use App\Actions\Workspace\CreatePersonalWorkspaceAction;
use App\Actions\Workspace\CreateTeamWorkspaceAction;
use App\Actions\Workspace\DeleteTeamWorkspaceAction;
use App\Actions\Workspace\RemoveWorkspaceMemberAction;
use App\Actions\Workspace\RenameWorkspaceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Api\V1\Workspace\UpdateWorkspaceRequest;
use App\Http\Resources\Api\V1\Workspace\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class WorkspaceController extends Controller
{
    /**
     * Workspaces the caller belongs to, the personal one first, each with the caller's role.
     */
    public function index(Request $request, CreatePersonalWorkspaceAction $personalWorkspace): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $personalWorkspace->handle($user);

        $workspaces = $user->workspaces()
            ->with('limitProfile')
            ->orderByRaw("CASE WHEN workspaces.type = 'personal' THEN 0 ELSE 1 END")
            ->orderBy('workspaces.name')
            ->orderBy('workspaces.id')
            ->get();

        return WorkspaceResource::collection($workspaces);
    }

    /**
     * Create a team workspace with the caller as its owner. Needs a verified email; capped per user.
     */
    public function store(StoreWorkspaceRequest $request, CreateTeamWorkspaceAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $action->handle($user, $request->name(), $request->slug());

        return (new WorkspaceResource($this->asMember($user, $workspace)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @scramble-return WorkspaceResource
     */
    public function show(Request $request, Workspace $workspace): WorkspaceResource
    {
        Gate::authorize('view', $workspace);

        /** @var User $user */
        $user = $request->user();

        return new WorkspaceResource($this->asMember($user, $workspace));
    }

    /**
     * Rename a team. Owners only; the slug does not change.
     *
     * @scramble-return WorkspaceResource
     */
    public function update(UpdateWorkspaceRequest $request, Workspace $workspace, RenameWorkspaceAction $action): WorkspaceResource
    {
        /** @var User $user */
        $user = $request->user();
        $action->handle($user, $workspace, $request->name());

        return new WorkspaceResource($this->asMember($user, $workspace));
    }

    /**
     * Delete a team that has no projects. Owners only.
     *
     * @scramble-return Response
     */
    public function destroy(Request $request, Workspace $workspace, DeleteTeamWorkspaceAction $action): Response
    {
        Gate::authorize('delete', $workspace);

        /** @var User $user */
        $user = $request->user();
        $action->handle($user, $workspace);

        return response()->noContent();
    }

    /**
     * Leave a team. The last owner cannot leave.
     *
     * @scramble-return Response
     */
    public function leave(Request $request, Workspace $workspace, RemoveWorkspaceMemberAction $action): Response
    {
        Gate::authorize('leave', $workspace);

        /** @var User $user */
        $user = $request->user();

        /** @var WorkspaceMember $membership */
        $membership = $workspace->members()->where('user_id', $user->id)->firstOrFail();
        $action->handle($user, $workspace, $membership);

        return response()->noContent();
    }

    private function asMember(User $user, Workspace $workspace): Workspace
    {
        /** @var Workspace */
        return $user->workspaces()->with('limitProfile')->whereKey($workspace->id)->firstOrFail();
    }
}
