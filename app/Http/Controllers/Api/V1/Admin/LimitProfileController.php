<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\DeleteLimitProfileAction;
use App\Actions\Admin\SaveLimitProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminPageRequest;
use App\Http\Requests\Api\V1\Admin\AdminReasonRequest;
use App\Http\Requests\Api\V1\Admin\StoreLimitProfileRequest;
use App\Http\Requests\Api\V1\Admin\UpdateLimitProfileRequest;
use App\Http\Resources\Api\V1\Admin\LimitProfileResource;
use App\Models\LimitProfile;
use App\Models\User;
use App\Support\Admin\AdminSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class LimitProfileController extends Controller
{
    /**
     * Limit profiles by name, each with the number of workspaces using it.
     */
    public function index(AdminPageRequest $request): AnonymousResourceCollection
    {
        $query = LimitProfile::query()->withCount('workspaces');

        AdminSearch::apply($query, ['name'], $request->searchTerm());

        return LimitProfileResource::collection(
            $query->orderBy('name')->orderBy('id')->paginate(perPage: $request->pageSize(), page: $request->pageNumber())->withQueryString(),
        );
    }

    /**
     * Create a limit profile.
     *
     * @status 201
     */
    public function store(StoreLimitProfileRequest $request, SaveLimitProfileAction $action): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        return (new LimitProfileResource($action->create($admin, $request->toData())->loadCount('workspaces')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * A limit profile.
     */
    public function show(LimitProfile $limitProfile): LimitProfileResource
    {
        return new LimitProfileResource($limitProfile->loadCount('workspaces'));
    }

    /**
     * Change a limit profile. Fields left out stay as they are; changes apply to every workspace using it at once.
     */
    public function update(UpdateLimitProfileRequest $request, LimitProfile $limitProfile, SaveLimitProfileAction $action): LimitProfileResource
    {
        /** @var User $admin */
        $admin = $request->user();

        return new LimitProfileResource($action->update($admin, $limitProfile, $request->toData()));
    }

    /**
     * Delete a limit profile no workspace uses.
     *
     * Answers 409 `LIMIT_PROFILE_IN_USE` with `workspaces` while any workspace runs under it.
     */
    public function destroy(AdminReasonRequest $request, LimitProfile $limitProfile, DeleteLimitProfileAction $action): Response
    {
        /** @var User $admin */
        $admin = $request->user();

        $action->handle($admin, $limitProfile, $request->reason());

        return response()->noContent();
    }
}
