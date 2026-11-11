<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetAdminProjectCollectionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\IndexAdminProjectRequest;
use App\Http\Resources\Api\V1\Admin\AdminProjectDetailResource;
use App\Http\Resources\Api\V1\Admin\AdminProjectResource;
use App\Models\Project;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProjectController extends Controller
{
    /**
     * Projects of every workspace, newest first.
     */
    public function index(IndexAdminProjectRequest $request, GetAdminProjectCollectionAction $action): AnonymousResourceCollection
    {
        return AdminProjectResource::collection($action->handle($request->toData()));
    }

    /**
     * A project with its owner, latest deployment, suspension, and recent admin control requests.
     */
    public function show(Project $project): AdminProjectDetailResource
    {
        $project
            ->load([
                'latestDeployment',
                'latestSuspendRequest',
                'workspace',
                'user:id,name,username',
                'controlRequests' => fn ($query) => $query->orderByDesc('id')->limit(AdminProjectDetailResource::RECENT_CONTROL_REQUESTS),
            ])
            ->loadCount('deployments');

        return new AdminProjectDetailResource($project);
    }
}
