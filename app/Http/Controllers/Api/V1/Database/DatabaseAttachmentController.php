<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Database;

use App\Actions\Database\AttachDatabaseAction;
use App\Actions\Database\DetachDatabaseAction;
use App\Exceptions\Database\DatabaseCrossRegionUnsupportedException;
use App\Exceptions\Database\DatabaseLockedException;
use App\Exceptions\Database\DatabaseNotReadyException;
use App\Exceptions\Database\ProjectDatabaseAlreadyAttachedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Database\DatabaseAttachmentResource;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Attach a workspace's database to one of its projects, or detach it. Both
 * take effect at the project's next deployment.
 */
final class DatabaseAttachmentController extends Controller
{
    /**
     * Attach the database to the project. `201`, or `200` when it already was. The project's next deployment receives
     * `DATABASE_URL`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`, except
     * those the project sets itself.
     *
     * @throws DatabaseLockedException
     * @throws DatabaseNotReadyException
     * @throws ProjectDatabaseAlreadyAttachedException
     * @throws DatabaseCrossRegionUnsupportedException
     */
    #[OpenApiResponse(201, 'The database is attached.', type: 'array{data: DatabaseAttachmentResource}')]
    #[OpenApiResponse(200, 'The database already was attached.', type: 'array{data: DatabaseAttachmentResource}')]
    public function store(Request $request, Workspace $workspace, ManagedDatabase $database, Project $project, AttachDatabaseAction $action): JsonResponse
    {
        $this->ensureSameWorkspace($workspace, $database, $project);
        Gate::authorize('update', $database);
        Gate::authorize('update', $project);

        /** @var User $user */
        $user = $request->user();
        $result = $action->handle($user, $database, $project);

        return (new DatabaseAttachmentResource($result['attachment']->load('project')))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    /**
     * Detach the database from the project. The data stays; the project's next deployment no longer receives the
     * connection variables.
     *
     * @status 204
     */
    public function destroy(Request $request, Workspace $workspace, ManagedDatabase $database, Project $project, DetachDatabaseAction $action): Response
    {
        $this->ensureSameWorkspace($workspace, $database, $project);
        Gate::authorize('update', $database);
        Gate::authorize('update', $project);

        /** @var User $user */
        $user = $request->user();
        $action->handle($user, $database, $project);

        return response()->noContent();
    }

    /**
     * A database or project of another workspace is missing here, like a
     * nested resource. Checked before the policies, so an outsider learns as
     * little as with any unknown ID.
     */
    private function ensureSameWorkspace(Workspace $workspace, ManagedDatabase $database, Project $project): void
    {
        abort_unless($database->workspace_id === $workspace->id && $project->workspace_id === $workspace->id, 404);
    }
}
