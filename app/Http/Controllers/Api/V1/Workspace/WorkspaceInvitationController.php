<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workspace;

use App\Actions\Workspace\InviteToWorkspaceAction;
use App\Actions\Workspace\RespondToWorkspaceInvitationAction;
use App\Actions\Workspace\RevokeWorkspaceInvitationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workspace\RespondWorkspaceInvitationRequest;
use App\Http\Requests\Api\V1\Workspace\StoreWorkspaceInvitationRequest;
use App\Http\Resources\Api\V1\Workspace\WorkspaceInvitationResource;
use App\Http\Resources\Api\V1\Workspace\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class WorkspaceInvitationController extends Controller
{
    /**
     * Pending invitations of a team, expired ones included until they are pruned. Owners only.
     */
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('manageMembers', $workspace);

        return WorkspaceInvitationResource::collection(
            $workspace->invitations()->with(['workspace', 'inviter'])->latest()->orderBy('id')->get(),
        );
    }

    /**
     * Invite an email address with a role. Owners only. Inviting the same address again replaces the pending
     * invitation and sends a new link.
     */
    public function store(StoreWorkspaceInvitationRequest $request, Workspace $workspace, InviteToWorkspaceAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $invitation = $action->handle($user, $workspace, $request->email(), $request->role());

        return (new WorkspaceInvitationResource($invitation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Revoke a pending invitation. Owners only.
     *
     * @scramble-return Response
     */
    public function destroy(Request $request, Workspace $workspace, WorkspaceInvitation $invitation, RevokeWorkspaceInvitationAction $action): Response
    {
        Gate::authorize('manageMembers', $workspace);

        /** @var User $user */
        $user = $request->user();
        $action->handle($user, $invitation->setRelation('workspace', $workspace));

        return response()->noContent();
    }

    /**
     * Invitations addressed to the caller's verified email that have not expired.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $invitations = $user->hasVerifiedEmail()
            ? WorkspaceInvitation::query()
                ->with(['workspace', 'inviter'])
                ->where('email', Str::lower($user->email))
                ->where('expires_at', '>', now())
                ->latest()
                ->orderBy('id')
                ->get()
            : collect();

        return WorkspaceInvitationResource::collection($invitations);
    }

    /**
     * Accept an invitation with the token from the email, or as the owner of the invited verified address.
     *
     * @scramble-return WorkspaceResource
     */
    public function accept(
        RespondWorkspaceInvitationRequest $request,
        WorkspaceInvitation $invitation,
        RespondToWorkspaceInvitationAction $action,
    ): WorkspaceResource {
        /** @var User $user */
        $user = $request->user();

        return new WorkspaceResource($action->accept($user, $invitation, $request->token()));
    }

    /**
     * Decline an invitation, under the same proof as accepting.
     *
     * @scramble-return Response
     */
    public function decline(
        RespondWorkspaceInvitationRequest $request,
        WorkspaceInvitation $invitation,
        RespondToWorkspaceInvitationAction $action,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $action->decline($user, $invitation, $request->token());

        return response()->noContent();
    }
}
