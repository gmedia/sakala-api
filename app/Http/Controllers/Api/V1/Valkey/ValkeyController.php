<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Valkey;

use App\Actions\Valkey\CreateValkeyAction;
use App\Actions\Valkey\DeleteValkeyAction;
use App\Actions\Valkey\RevealValkeyCredentialsAction;
use App\Actions\Valkey\RotateValkeyPasswordAction;
use App\Actions\Valkey\UpdateValkeyAction;
use App\Exceptions\Valkey\ProjectValkeyAlreadyAttachedException;
use App\Exceptions\Valkey\ValkeyBusyException;
use App\Exceptions\Valkey\ValkeyCapacityUnavailableException;
use App\Exceptions\Valkey\ValkeyInUseException;
use App\Exceptions\Valkey\ValkeyLimitExceededException;
use App\Exceptions\Valkey\ValkeyMemoryLimitExceededException;
use App\Exceptions\Valkey\ValkeyNodeMismatchException;
use App\Exceptions\Valkey\ValkeyNotReadyException;
use App\Exceptions\Valkey\ValkeyPasswordRotatingException;
use App\Exceptions\Valkey\ValkeyUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Valkey\RotateValkeyPasswordRequest;
use App\Http\Requests\Api\V1\Valkey\StoreValkeyRequest;
use App\Http\Requests\Api\V1\Valkey\UpdateValkeyRequest;
use App\Http\Resources\Api\V1\Valkey\ValkeyCredentialsResource;
use App\Http\Resources\Api\V1\Valkey\ValkeyResource;
use App\Models\ManagedValkey;
use App\Models\User;
use App\Models\Workspace;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Managed Valkey stores of a workspace (ADR-023). Every endpoint answers
 * `404` while `SAKALA_VALKEY_ENABLED` is off. Any member reads them; owners
 * and members change them; only owners read the password. Admins keep every
 * power and are exempt from the workspace's store count and memory limits,
 * but not from the node budget.
 */
final class ValkeyController extends Controller
{
    /**
     * The workspace's Valkey stores, newest first. Deleted ones are gone; quotas are in `GET app/pilot-limits`.
     */
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ManagedValkey::class, $workspace]);

        return ValkeyResource::collection(
            $workspace->valkeys()->with(ValkeyResource::RELATIONS)->latest()->orderByDesc('id')->get(),
        );
    }

    /**
     * Create a store. It is `provisioning` until the node creates it; its size is reserved from the workspace's
     * Valkey memory at once. With `project_id` it goes to the node the project runs on and is attached to that
     * project in the same step. Needs the current legal terms accepted (`LEGAL_ACCEPTANCE_REQUIRED`).
     *
     * @throws ProjectValkeyAlreadyAttachedException
     * @throws ValkeyLimitExceededException
     * @throws ValkeyMemoryLimitExceededException
     * @throws ValkeyUnavailableException
     * @throws ValkeyCapacityUnavailableException
     * @throws ValkeyNodeMismatchException
     */
    public function store(StoreValkeyRequest $request, Workspace $workspace, CreateValkeyAction $action): JsonResponse
    {
        $data = $request->toData();

        if ($data->project !== null) {
            Gate::authorize('update', $data->project);
        }

        /** @var User $user */
        $user = $request->user();
        $valkey = $action->handle($user, $workspace, $data);

        return (new ValkeyResource($valkey->load(ValkeyResource::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Workspace $workspace, ManagedValkey $valkey): ValkeyResource
    {
        Gate::authorize('view', $valkey);

        return new ValkeyResource($valkey->load(ValkeyResource::RELATIONS));
    }

    /**
     * Rename the store and/or resize it. `200` for a rename only or an unchanged size; `202` when a resize was sent
     * to the node (`pending_maxmemory_mb`). The mode never changes.
     *
     * @throws ValkeyNotReadyException
     * @throws ValkeyBusyException
     * @throws ValkeyMemoryLimitExceededException
     * @throws ValkeyCapacityUnavailableException
     */
    #[OpenApiResponse(200, 'Renamed, or the size is unchanged.', type: 'array{data: ValkeyResource}')]
    #[OpenApiResponse(202, 'A resize is on its way to the node.', type: 'array{data: ValkeyResource}')]
    public function update(UpdateValkeyRequest $request, Workspace $workspace, ManagedValkey $valkey, UpdateValkeyAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $action->handle($user, $valkey, $request->newName(), $request->newMaxmemoryMb());

        return (new ValkeyResource($result['valkey']->load(ValkeyResource::RELATIONS)))
            ->response()
            ->setStatusCode($result['resize_requested'] ? 202 : 200);
    }

    /**
     * Delete the store with all its data. Answers `202` with `status: deleting`; it is gone once the node deletes
     * it. `204` when a store still `provisioning` was removed before any node started creating it. Only a store
     * without attached projects can be deleted; a detached project must be redeployed first, or the node refuses
     * (`last_failure.code` `valkey_in_use`). Backups of a persistent store are kept for about 14 days.
     *
     * @throws ValkeyInUseException
     * @throws ValkeyBusyException
     */
    #[OpenApiResponse(202, 'The node is deleting the store.', type: 'array{data: ValkeyResource}')]
    #[OpenApiResponse(204, 'The store was removed before a node started creating it.')]
    public function destroy(Request $request, Workspace $workspace, ManagedValkey $valkey, DeleteValkeyAction $action): JsonResponse|Response
    {
        Gate::authorize('delete', $valkey);

        /** @var User $user */
        $user = $request->user();
        $result = $action->handle($user, $valkey);

        if ($result['removed']) {
            return response()->noContent();
        }

        return (new ValkeyResource($result['valkey']->load(ValkeyResource::RELATIONS)))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * The store's address and password and the variables attached projects receive. Owners and admins only, recorded
     * in the audit trail before it is sent, never cached. `409 VALKEY_PASSWORD_ROTATING` while a rotation reached the
     * node and the node has not confirmed which password works.
     *
     * @throws ValkeyNotReadyException
     * @throws ValkeyPasswordRotatingException
     */
    public function credentials(Request $request, Workspace $workspace, ManagedValkey $valkey, RevealValkeyCredentialsAction $action): JsonResponse
    {
        Gate::authorize('viewCredentials', $valkey);

        /** @var User $user */
        $user = $request->user();
        $credentials = $action->handle($user, $valkey, [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return (new ValkeyCredentialsResource($credentials))
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Ask the node for a new password. Answers `202` with `password_rotation.status: pending`. The node switches at
     * once and closes the connections that are logged in, so running applications fail until they are redeployed.
     * Once the node reports, attached projects show `redeploy_required`, and with `redeploy_attached` the API
     * redeploys the attached projects that have a live deployment (`password_rotation.redeploys`).
     *
     * @throws ValkeyNotReadyException
     * @throws ValkeyBusyException
     */
    public function rotatePassword(RotateValkeyPasswordRequest $request, Workspace $workspace, ManagedValkey $valkey, RotateValkeyPasswordAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new ValkeyResource($action->handle($user, $valkey, $request->redeployAttached())->load(ValkeyResource::RELATIONS)))
            ->response()
            ->setStatusCode(202);
    }
}
