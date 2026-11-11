<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetAdminWorkspaceCollectionAction;
use App\Actions\Admin\GetAdminWorkspaceDetailAction;
use App\Actions\Workspace\AssignLimitProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AssignLimitProfileRequest;
use App\Http\Requests\Api\V1\Admin\IndexAdminWorkspaceRequest;
use App\Http\Resources\Api\V1\Admin\AdminWorkspaceDetailResource;
use App\Http\Resources\Api\V1\Admin\AdminWorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class WorkspaceController extends Controller
{
    /**
     * Personal and team workspaces, newest first.
     */
    public function index(IndexAdminWorkspaceRequest $request, GetAdminWorkspaceCollectionAction $action): AnonymousResourceCollection
    {
        return AdminWorkspaceResource::collection($action->handle($request->toData()));
    }

    /**
     * A workspace with its members, projects, effective limits, and usage.
     */
    public function show(Workspace $workspace, GetAdminWorkspaceDetailAction $action): AdminWorkspaceDetailResource
    {
        return new AdminWorkspaceDetailResource($action->handle($workspace));
    }

    /**
     * Attach a limit profile, or `null` to return to the default of the workspace type.
     *
     * Limits already reached are not undone; a lower limit only stops new projects and deployments.
     */
    public function assignLimitProfile(AssignLimitProfileRequest $request, Workspace $workspace, AssignLimitProfileAction $action): AdminWorkspaceResource
    {
        /** @var User $admin */
        $admin = $request->user();

        $workspace = $action->handle($admin, $workspace, $request->limitProfile(), $request->reason());

        return new AdminWorkspaceResource($workspace->load(['limitProfile', 'personalUser:id,name,username'])->loadCount(['members', 'projects']));
    }
}
