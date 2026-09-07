<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\StopProjectAction;
use App\Actions\Admin\SuspendProjectAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StopProjectRequest;
use App\Http\Requests\Api\V1\Admin\SuspendProjectRequest;
use App\Http\Resources\Api\V1\Admin\ProjectControlResource;
use App\Models\Project;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;

final class ProjectControlController extends Controller
{
    // Untuk Testing
    #[HeaderParameter(
        'Idempotency-Key',
        description: 'Unique key used to safely retry a deployment request.',
        type: 'string',
        example: '550e8400-e29b-41d4-a716-446655440000',
    )]
    /**
     * Stop a project.
     *
     * @scramble-return \Illuminate\Http\Response
     */
    public function stop(
        StopProjectRequest $request,
        Project $project,
        StopProjectAction $action,
    ): JsonResponse {
        $result = $action->handle(
            project: $project,
            user: $request->user(),
            data: $request->toData(),
        );

        return (new ProjectControlResource($result))
            ->response()
            ->setStatusCode(202);
    }

    #[HeaderParameter(
        'Idempotency-Key',
        description: 'Unique key used to safely retry a deployment request.',
        type: 'string',
        example: '550e8400-e29b-41d4-a716-446655440000',
    )]
    /**
     * Suspend a project.
     *
     * @scramble-return \Illuminate\Http\Response
     */
    public function suspend(
        SuspendProjectRequest $request,
        Project $project,
        SuspendProjectAction $action,
    ): JsonResponse {
        $result = $action->handle(
            project: $project,
            user: $request->user(),
            data: $request->toData(),
        );

        return (new ProjectControlResource($result))
            ->response()
            ->setStatusCode(202);
    }
}
