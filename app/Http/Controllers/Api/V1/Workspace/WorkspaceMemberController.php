<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workspace;

use App\Actions\Workspace\ChangeWorkspaceMemberRoleAction;
use App\Actions\Workspace\RemoveWorkspaceMemberAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workspace\UpdateWorkspaceMemberRequest;
use App\Http\Resources\Api\V1\Workspace\WorkspaceMemberResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class WorkspaceMemberController extends Controller
{
    /**
     * Members of the workspace. Any member may list them; only owners see email addresses.
     */
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('view', $workspace);

        /** @var User $user */
        $user = $request->user();
        $showEmail = WorkspaceMemberResource::canSeeEmails($workspace->roleOf($user));

        $members = $workspace->members()
            ->with('user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (WorkspaceMember $member): WorkspaceMemberResource => (new WorkspaceMemberResource($member))->withEmail($showEmail));

        return WorkspaceMemberResource::collection($members);
    }

    /**
     * Change a member's role. Owners only; the last owner cannot be demoted.
     *
     * @scramble-return WorkspaceMemberResource
     */
    public function update(
        UpdateWorkspaceMemberRequest $request,
        Workspace $workspace,
        WorkspaceMember $member,
        ChangeWorkspaceMemberRoleAction $action,
    ): WorkspaceMemberResource {
        /** @var User $user */
        $user = $request->user();
        $updated = $action->handle($user, $workspace, $member, $request->role());

        return (new WorkspaceMemberResource($updated->load('user')))->withEmail(true);
    }

    /**
     * Remove a member. Owners only; the last owner cannot be removed. Access ends at once.
     *
     * @scramble-return Response
     */
    public function destroy(Request $request, Workspace $workspace, WorkspaceMember $member, RemoveWorkspaceMemberAction $action): Response
    {
        Gate::authorize('manageMembers', $workspace);

        /** @var User $user */
        $user = $request->user();
        $action->handle($user, $workspace, $member);

        return response()->noContent();
    }
}
