<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\ChangeUserRoleAction;
use App\Actions\Admin\GetAdminUserCollectionAction;
use App\Actions\Admin\GetAdminUserDetailAction;
use App\Actions\Admin\SetUserTeamLimitAction;
use App\Actions\Workspace\AssignLimitProfileAction;
use App\Actions\Workspace\CreatePersonalWorkspaceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AssignLimitProfileRequest;
use App\Http\Requests\Api\V1\Admin\IndexAdminUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRoleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserTeamLimitRequest;
use App\Http\Resources\Api\V1\Admin\AdminUserDetailResource;
use App\Http\Resources\Api\V1\Admin\AdminUserResource;
use App\Http\Resources\Api\V1\Admin\AdminWorkspaceResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UserController extends Controller
{
    /**
     * Accounts, newest first.
     */
    public function index(IndexAdminUserRequest $request, GetAdminUserCollectionAction $action): AnonymousResourceCollection
    {
        return AdminUserResource::collection($action->handle($request->toData()));
    }

    /**
     * An account with its workspaces and roles, personal workspace limits, and recent audit events.
     */
    public function show(User $user, GetAdminUserDetailAction $action): AdminUserDetailResource
    {
        return new AdminUserDetailResource($action->handle($user));
    }

    /**
     * Promote a user to admin or demote an admin.
     *
     * Answers 409 `ADMIN_SELF_DEMOTION` when demoting yourself and `LAST_ADMIN` when it would leave no admin. Setting
     * the role a user already has changes nothing and records nothing.
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user, ChangeUserRoleAction $action): AdminUserResource
    {
        /** @var User $admin */
        $admin = $request->user();

        return new AdminUserResource($action->handle($admin, $user, $request->role(), $request->reason())->loadCount('projects'));
    }

    /**
     * Set how many teams a user may own, or `null` for the configured default.
     */
    public function updateTeamLimit(UpdateUserTeamLimitRequest $request, User $user, SetUserTeamLimitAction $action): AdminUserResource
    {
        /** @var User $admin */
        $admin = $request->user();

        return new AdminUserResource($action->handle($admin, $user, $request->maxOwnedTeams(), $request->reason())->loadCount('projects'));
    }

    /**
     * Attach a limit profile to the user's personal workspace, or `null` to return to the personal default.
     */
    public function assignLimitProfile(
        AssignLimitProfileRequest $request,
        User $user,
        CreatePersonalWorkspaceAction $personalWorkspace,
        AssignLimitProfileAction $action,
    ): AdminWorkspaceResource {
        /** @var User $admin */
        $admin = $request->user();

        $workspace = $action->handle($admin, $personalWorkspace->handle($user), $request->limitProfile(), $request->reason());

        return new AdminWorkspaceResource($workspace->load(['limitProfile', 'personalUser:id,name,username'])->loadCount(['members', 'projects']));
    }
}
