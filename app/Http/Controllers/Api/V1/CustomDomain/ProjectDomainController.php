<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\CustomDomain;

use App\Actions\CustomDomain\AddCustomDomainAction;
use App\Actions\CustomDomain\CheckCustomDomainAction;
use App\Actions\CustomDomain\DeleteCustomDomainAction;
use App\Enums\CustomDomainStatus;
use App\Exceptions\CustomDomain\CustomDomainLimitExceededException;
use App\Exceptions\CustomDomain\CustomDomainTakenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomDomain\StoreProjectDomainRequest;
use App\Http\Resources\Api\V1\CustomDomain\ProjectDomainResource;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Services\CustomDomain\CustomDomainRouteSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Custom domains of a project (ADR-024). Every endpoint answers `404` while
 * `SAKALA_CUSTOM_DOMAINS_ENABLED` is off. Any member of the workspace reads
 * them; owners and members add, check, and delete them; admins keep every
 * power. The quota is in `GET app/pilot-limits`.
 */
final class ProjectDomainController extends Controller
{
    public function __construct(
        private readonly CustomDomainRouteSync $routes,
    ) {}

    /** The project's custom domains, oldest first; deleted ones are gone. */
    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        $waiting = $this->routes->waitingForNode($project);
        $resources = ProjectDomainResource::collection($project->domains()->oldest()->orderBy('id')->get());
        $resources->collection->each(static fn (ProjectDomainResource $resource): ProjectDomainResource => $resource->withWaitingForNode($waiting && $resource->resource->status === CustomDomainStatus::Verified));

        return $resources;
    }

    /**
     * Add a custom domain. It is `pending` with a new token until its DNS records are published and pass a check;
     * Sakala checks every minute for the first 30 minutes. A name verified on another project is refused with `409
     * DOMAIN_TAKEN`; a pending claim elsewhere does not block.
     *
     * @throws CustomDomainLimitExceededException
     * @throws CustomDomainTakenException
     */
    public function store(StoreProjectDomainRequest $request, Project $project, AddCustomDomainAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $domain = $action->handle($user, $project, $request->toData());

        return (new ProjectDomainResource($domain->refresh()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Check the domain's DNS now and apply the result, as the scheduled check would; a disabled domain whose DNS
     * passes is active again. At most 6 per minute per domain (`429` beyond).
     */
    public function check(Request $request, Project $project, ProjectDomain $domain, CheckCustomDomainAction $action): ProjectDomainResource
    {
        $this->authorize('update', $project);

        /** @var User $user */
        $user = $request->user();
        $checked = $action->handle($domain, $user);

        return (new ProjectDomainResource($checked))
            ->withWaitingForNode($checked->status === CustomDomainStatus::Verified && $this->routes->waitingForNode($project));
    }

    /** Delete the domain. It stops routing within about 10 seconds; `<slug>.<project domain>` is not affected. */
    public function destroy(Request $request, Project $project, ProjectDomain $domain, DeleteCustomDomainAction $action): Response
    {
        $this->authorize('update', $project);

        /** @var User $user */
        $user = $request->user();
        $action->handle($user, $domain);

        return response()->noContent();
    }
}
