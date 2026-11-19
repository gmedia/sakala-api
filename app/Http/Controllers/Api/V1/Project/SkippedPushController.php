<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Project;

use App\Actions\Project\GetSkippedPushCollectionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\IndexSkippedPushRequest;
use App\Http\Resources\Api\V1\Project\SkippedPushResource;
use App\Models\Project;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SkippedPushController extends Controller
{
    /**
     * Pushes that auto-deploy skipped because nothing in them concerned the project, newest first.
     *
     * In a monorepo a push deploys a project only when it changes a file in the project's root directory or a
     * file in no project's folder. The other pushes appear here, not as deployments: they have no number, count
     * against no limit, and notify no one. Pushes skipped for a limit or a problem are notifications instead.
     */
    public function __invoke(
        Project $project,
        IndexSkippedPushRequest $request,
        GetSkippedPushCollectionAction $action,
    ): AnonymousResourceCollection {
        return SkippedPushResource::collection($action->handle($project, $request->toData()));
    }
}
