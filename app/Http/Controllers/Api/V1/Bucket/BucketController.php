<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Bucket;

use App\Actions\Bucket\CreateBucketAction;
use App\Actions\Bucket\DeleteBucketAction;
use App\Actions\Bucket\RevealBucketCredentialsAction;
use App\Actions\Bucket\RotateBucketKeyAction;
use App\Actions\Bucket\UpdateBucketAction;
use App\Exceptions\Bucket\BucketBusyException;
use App\Exceptions\Bucket\BucketCapacityUnavailableException;
use App\Exceptions\Bucket\BucketInUseException;
use App\Exceptions\Bucket\BucketLimitExceededException;
use App\Exceptions\Bucket\BucketNotReadyException;
use App\Exceptions\Bucket\BucketRegionUnavailableException;
use App\Exceptions\Bucket\BucketStorageLimitExceededException;
use App\Exceptions\Bucket\BucketUnavailableException;
use App\Exceptions\Bucket\ProjectBucketAlreadyAttachedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bucket\RotateBucketKeyRequest;
use App\Http\Requests\Api\V1\Bucket\StoreBucketRequest;
use App\Http\Requests\Api\V1\Bucket\UpdateBucketRequest;
use App\Http\Resources\Api\V1\Bucket\BucketCredentialsResource;
use App\Http\Resources\Api\V1\Bucket\BucketResource;
use App\Models\ManagedBucket;
use App\Models\User;
use App\Models\Workspace;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Managed S3 buckets of a workspace (ADR-021). Any member reads them; owners
 * and members change them; only owners read the secret access key. Admins
 * keep every power and are exempt from the workspace's bucket count and
 * storage limits, but not from node capacity.
 */
final class BucketController extends Controller
{
    /**
     * The workspace's buckets, newest first. Deleted ones are gone; quotas and usage are in `GET app/pilot-limits`.
     */
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ManagedBucket::class, $workspace]);

        return BucketResource::collection(
            $workspace->buckets()->with(BucketResource::RELATIONS)->latest()->orderByDesc('id')->get(),
        );
    }

    /**
     * Create a private bucket. It is `provisioning` until the storage node creates it; its quota is reserved from the
     * workspace's bucket storage at once. With `project_id` it is attached to that project in the same step. Needs
     * the current legal terms accepted (`LEGAL_ACCEPTANCE_REQUIRED`).
     *
     * @throws BucketUnavailableException
     * @throws ProjectBucketAlreadyAttachedException
     * @throws BucketLimitExceededException
     * @throws BucketStorageLimitExceededException
     * @throws BucketRegionUnavailableException
     * @throws BucketCapacityUnavailableException
     */
    public function store(StoreBucketRequest $request, Workspace $workspace, CreateBucketAction $action): JsonResponse
    {
        $data = $request->toData();

        if ($data->project !== null) {
            Gate::authorize('update', $data->project);
        }

        /** @var User $user */
        $user = $request->user();
        $bucket = $action->handle($user, $workspace, $data);

        return (new BucketResource($bucket->load(BucketResource::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Workspace $workspace, ManagedBucket $bucket): BucketResource
    {
        Gate::authorize('view', $bucket);

        return new BucketResource($bucket->load(BucketResource::RELATIONS));
    }

    /**
     * Rename the bucket and/or change its quota. `200` for a rename only or an unchanged quota; `202` when a quota
     * change was sent to the storage node (`pending_quota_mb`). The name on the node never changes.
     *
     * @throws BucketNotReadyException
     * @throws BucketBusyException
     * @throws BucketStorageLimitExceededException
     * @throws BucketCapacityUnavailableException
     */
    #[OpenApiResponse(200, 'Renamed, or the quota is unchanged.', type: 'array{data: BucketResource}')]
    #[OpenApiResponse(202, 'A quota change is on its way to the storage node.', type: 'array{data: BucketResource}')]
    public function update(UpdateBucketRequest $request, Workspace $workspace, ManagedBucket $bucket, UpdateBucketAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $action->handle($user, $bucket, $request->newName(), $request->newQuotaMb());

        return (new BucketResource($result['bucket']->load(BucketResource::RELATIONS)))
            ->response()
            ->setStatusCode($result['quota_change_requested'] ? 202 : 200);
    }

    /**
     * Delete the bucket with every object in it. Answers `202` with `status: deleting`; it is gone once the storage
     * node deletes it. `204` when a bucket still `provisioning` was removed before any node started creating it.
     * Only a bucket without attached projects can be deleted. Backups keep the files for up to 14 days.
     *
     * @throws BucketInUseException
     * @throws BucketBusyException
     */
    #[OpenApiResponse(202, 'The storage node is deleting the bucket.', type: 'array{data: BucketResource}')]
    #[OpenApiResponse(204, 'The bucket was removed before a node started creating it.')]
    public function destroy(Request $request, Workspace $workspace, ManagedBucket $bucket, DeleteBucketAction $action): JsonResponse|Response
    {
        Gate::authorize('delete', $bucket);

        /** @var User $user */
        $user = $request->user();
        $result = $action->handle($user, $bucket);

        if ($result['removed']) {
            return response()->noContent();
        }

        return (new BucketResource($result['bucket']->load(BucketResource::RELATIONS)))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * The bucket's key with its secret and the variables attached projects receive. Owners and admins only, recorded
     * in the audit trail before it is sent, never cached. The key works from the internet.
     *
     * @throws BucketNotReadyException
     */
    public function credentials(Request $request, Workspace $workspace, ManagedBucket $bucket, RevealBucketCredentialsAction $action): JsonResponse
    {
        Gate::authorize('viewCredentials', $bucket);

        /** @var User $user */
        $user = $request->user();
        $credentials = $action->handle($user, $bucket, [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return (new BucketCredentialsResource($credentials))
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Ask the storage node for a new secret access key; the access key ID stays. Answers `202` with
     * `key_rotation.status: pending`. The node switches at once, so running applications lose access until they are
     * redeployed. Once the node reports, attached projects show `redeploy_required`, and with `redeploy_attached` the
     * API redeploys the attached projects that have a live deployment (`key_rotation.redeploys`).
     *
     * @throws BucketNotReadyException
     * @throws BucketBusyException
     */
    public function rotateKey(RotateBucketKeyRequest $request, Workspace $workspace, ManagedBucket $bucket, RotateBucketKeyAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new BucketResource($action->handle($user, $bucket, $request->redeployAttached())->load(BucketResource::RELATIONS)))
            ->response()
            ->setStatusCode(202);
    }
}
