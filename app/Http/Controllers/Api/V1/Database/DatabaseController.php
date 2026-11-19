<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Database;

use App\Actions\Database\CreateDatabaseAction;
use App\Actions\Database\DeleteDatabaseAction;
use App\Actions\Database\InstallDatabaseExtensionAction;
use App\Actions\Database\RenameDatabaseAction;
use App\Actions\Database\RevealDatabaseCredentialsAction;
use App\Actions\Database\RotateDatabasePasswordAction;
use App\Exceptions\Database\DatabaseBusyException;
use App\Exceptions\Database\DatabaseInUseException;
use App\Exceptions\Database\DatabaseLimitExceededException;
use App\Exceptions\Database\DatabaseNotReadyException;
use App\Exceptions\Database\DatabaseRegionUnavailableException;
use App\Exceptions\Database\DatabaseStorageLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Database\InstallDatabaseExtensionRequest;
use App\Http\Requests\Api\V1\Database\RotateDatabasePasswordRequest;
use App\Http\Requests\Api\V1\Database\StoreDatabaseRequest;
use App\Http\Requests\Api\V1\Database\UpdateDatabaseRequest;
use App\Http\Resources\Api\V1\Database\DatabaseCredentialsResource;
use App\Http\Resources\Api\V1\Database\DatabaseResource;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Managed PostgreSQL databases of a workspace (ADR-020). Any member reads
 * them; owners and members change them; only owners read credentials. Admins
 * keep every power.
 */
final class DatabaseController extends Controller
{
    /**
     * The workspace's databases, newest first. Dropped ones are gone; quotas and usage are in `GET app/pilot-limits`.
     */
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ManagedDatabase::class, $workspace]);

        return DatabaseResource::collection(
            $workspace->databases()->with(DatabaseResource::RELATIONS)->latest()->orderByDesc('id')->get(),
        );
    }

    /**
     * Create a database. It is `provisioning` until the database node of its region creates it.
     *
     * @throws DatabaseLimitExceededException
     * @throws DatabaseStorageLimitExceededException
     * @throws DatabaseRegionUnavailableException
     */
    public function store(StoreDatabaseRequest $request, Workspace $workspace, CreateDatabaseAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $database = $action->handle($user, $workspace, $request->name(), $request->region());

        return (new DatabaseResource($database->load(DatabaseResource::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Workspace $workspace, ManagedDatabase $database): DatabaseResource
    {
        Gate::authorize('view', $database);

        return new DatabaseResource($database->load(DatabaseResource::RELATIONS));
    }

    /**
     * Rename the database. The names on the database node never change.
     */
    public function update(UpdateDatabaseRequest $request, Workspace $workspace, ManagedDatabase $database, RenameDatabaseAction $action): DatabaseResource
    {
        /** @var User $user */
        $user = $request->user();

        return new DatabaseResource($action->handle($user, $database, $request->name())->load(DatabaseResource::RELATIONS));
    }

    /**
     * Delete the database and its data. Answers `202` with `status: deleting`; it is gone once the database node drops
     * it. Only a database without attached projects can be deleted.
     *
     * @throws DatabaseInUseException
     * @throws DatabaseBusyException
     */
    public function destroy(Request $request, Workspace $workspace, ManagedDatabase $database, DeleteDatabaseAction $action): JsonResponse
    {
        Gate::authorize('delete', $database);

        /** @var User $user */
        $user = $request->user();

        return (new DatabaseResource($action->handle($user, $database)->load(DatabaseResource::RELATIONS)))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Connection details with the password. Owners and admins only, recorded in the audit trail, never cached.
     *
     * @throws DatabaseNotReadyException
     */
    public function credentials(Request $request, Workspace $workspace, ManagedDatabase $database, RevealDatabaseCredentialsAction $action): JsonResponse
    {
        Gate::authorize('viewCredentials', $database);

        /** @var User $user */
        $user = $request->user();
        $credentials = $action->handle($user, $database, [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return (new DatabaseCredentialsResource($credentials))
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Ask the database node to install an extension from the allow-list. Answers `202`; the result is in the audit
     * trail and, for a failure, in `last_failure`. `vector` may be unavailable on a node that does not ship pgvector.
     *
     * @throws DatabaseNotReadyException
     */
    public function installExtension(InstallDatabaseExtensionRequest $request, Workspace $workspace, ManagedDatabase $database, InstallDatabaseExtensionAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new DatabaseResource($action->handle($user, $database, $request->extension())->load(DatabaseResource::RELATIONS)))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Ask the database node for a new password. Answers `202` with `password_rotation.status: pending`. The node
     * switches at once: the old password stops connecting right away, so running applications cannot open new
     * connections until they are redeployed. Once the node reports, `password_rotation.status` is `completed`,
     * attached projects show `redeploy_required`, and with `redeploy_attached` the API redeploys the attached projects
     * that have a live deployment (`password_rotation.redeploys`).
     *
     * @throws DatabaseNotReadyException
     * @throws DatabaseBusyException
     */
    public function rotatePassword(RotateDatabasePasswordRequest $request, Workspace $workspace, ManagedDatabase $database, RotateDatabasePasswordAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new DatabaseResource($action->handle($user, $database, $request->redeployAttached())->load(DatabaseResource::RELATIONS)))
            ->response()
            ->setStatusCode(202);
    }
}
