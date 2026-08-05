<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Project;

use App\Actions\Project\CreateProjectAction;
use App\Actions\Project\GetProjectCollectionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\IndexProjectRequest;
use App\Http\Requests\Api\V1\Project\StoreProjectRequest;
use App\Http\Resources\Api\V1\Project\CreateProjectResource;
use App\Http\Resources\Api\V1\Project\GetCollectionProjectResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(
        GetProjectCollectionAction $action,
        IndexProjectRequest $request
    ): AnonymousResourceCollection {
        $projects = $action->handle(
            $request->user(),
            $request->toData()
        );

        return GetCollectionProjectResource::collection($projects);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(
        StoreProjectRequest $request,
        CreateProjectAction $action
    ): JsonResponse {
        $project = $action->handle(
            $request->user(),
            $request->toData()
        );

        return (new CreateProjectResource($project))
            ->response()
            ->setStatusCode(201);
    }
}
