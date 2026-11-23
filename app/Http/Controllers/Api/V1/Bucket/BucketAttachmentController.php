<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Bucket;

use App\Actions\Bucket\AttachBucketAction;
use App\Actions\Bucket\DetachBucketAction;
use App\Exceptions\Bucket\BucketLockedException;
use App\Exceptions\Bucket\BucketNotReadyException;
use App\Exceptions\Bucket\ProjectBucketAlreadyAttachedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Bucket\BucketAttachmentResource;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Attach a workspace's bucket to one of its projects, or detach it. Both
 * take effect at the project's next deployment.
 */
final class BucketAttachmentController extends Controller
{
    /**
     * Attach the bucket to the project. `201`, or `200` when it already was. The project's next deployment receives
     * `AWS_ENDPOINT_URL_S3`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`, `AWS_DEFAULT_REGION`, `AWS_REGION`,
     * `AWS_BUCKET`, `AWS_ACCESS_KEY_ID`, and `AWS_SECRET_ACCESS_KEY`, except those the project sets itself.
     *
     * @throws BucketLockedException
     * @throws BucketNotReadyException
     * @throws ProjectBucketAlreadyAttachedException
     */
    #[OpenApiResponse(201, 'The bucket is attached.', type: 'array{data: BucketAttachmentResource}')]
    #[OpenApiResponse(200, 'The bucket already was attached.', type: 'array{data: BucketAttachmentResource}')]
    public function store(Request $request, Workspace $workspace, ManagedBucket $bucket, Project $project, AttachBucketAction $action): JsonResponse
    {
        $this->ensureSameWorkspace($workspace, $bucket, $project);
        Gate::authorize('update', $bucket);
        Gate::authorize('update', $project);

        /** @var User $user */
        $user = $request->user();
        $result = $action->handle($user, $bucket, $project);

        return (new BucketAttachmentResource($result['attachment']->load('project')))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    /**
     * Detach the bucket from the project. The files stay; the project's next deployment no longer receives the
     * bucket variables. `404` when the bucket is not attached to the project.
     *
     * @status 204
     */
    public function destroy(Request $request, Workspace $workspace, ManagedBucket $bucket, Project $project, DetachBucketAction $action): Response
    {
        $this->ensureSameWorkspace($workspace, $bucket, $project);
        Gate::authorize('update', $bucket);
        Gate::authorize('update', $project);

        /** @var User $user */
        $user = $request->user();
        $action->handle($user, $bucket, $project);

        return response()->noContent();
    }

    /**
     * A bucket or project of another workspace is missing here, like a
     * nested resource. Checked before the policies, so an outsider learns as
     * little as with any unknown ID.
     */
    private function ensureSameWorkspace(Workspace $workspace, ManagedBucket $bucket, Project $project): void
    {
        abort_unless($bucket->workspace_id === $workspace->id && $project->workspace_id === $workspace->id, 404);
    }
}
