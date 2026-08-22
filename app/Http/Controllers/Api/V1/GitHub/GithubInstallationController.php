<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\GitHub;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GitHub\IndexGithubInstallationBranchRequest;
use App\Http\Requests\Api\V1\GitHub\IndexGithubInstallationRepositoryRequest;
use App\Http\Resources\Api\V1\GitHub\GithubBranchResource;
use App\Http\Resources\Api\V1\GitHub\GithubInstallationResource;
use App\Http\Resources\Api\V1\GitHub\GithubResource;
use App\Models\GithubInstallation;
use App\Services\GitHub\GithubInstallationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class GithubInstallationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return GithubInstallationResource::collection($request->user()->githubInstallations()->latest()->get());
    }

    public function repositories(IndexGithubInstallationRepositoryRequest $request, GithubInstallation $installation, GithubInstallationService $service): AnonymousResourceCollection
    {
        abort_unless($installation->user_id === $request->user()->id || $request->user()->isAdmin(), 403);

        return GithubResource::collection($service->repositories($installation, $request->integer('page', 1), $request->integer('per_page', 30)));
    }

    public function branches(IndexGithubInstallationBranchRequest $request, GithubInstallation $installation, int $repositoryId, GithubInstallationService $service): AnonymousResourceCollection
    {
        abort_unless($installation->user_id === $request->user()->id || $request->user()->isAdmin(), 403);

        return GithubBranchResource::collection($service->branches($installation, $repositoryId));
    }
}
