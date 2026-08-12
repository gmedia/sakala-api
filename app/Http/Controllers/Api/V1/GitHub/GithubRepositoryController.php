<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\GitHub;

use App\Actions\GitHub\GetBranchAction;
use App\Actions\GitHub\GetRepositoryGithubCollectionAction;
use App\Actions\GitHub\GetRepositoryGithubCountAction;
use App\Actions\GitHub\ValidateRepositoryUrlAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GitHub\GetBranchRequest;
use App\Http\Requests\Api\V1\GitHub\GetGithubRequest;
use App\Http\Requests\Api\V1\GitHub\ValidateUrlRequest;
use App\Http\Resources\Api\V1\GitHub\GithubRepositoryCollectionResource;
use App\Http\Resources\Api\V1\GitHub\GithubRepositoryCountResource;
use App\Http\Resources\Api\V1\GitHub\GithubResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GithubRepositoryController extends Controller
{
    public function index(
        GetGithubRequest $request,
        GetRepositoryGithubCollectionAction $action,
    ): GithubRepositoryCollectionResource {
        $repositories = $action->handle(
            $request->user(),
            $request->toData()
        );

        return new GithubRepositoryCollectionResource($repositories);
    }

    public function validate(
        ValidateUrlRequest $request,
        ValidateRepositoryUrlAction $action,
    ): GithubResource {

        $repository = $action->handle(
            $request->user(),
            $request->toData()
        );

        return new GithubResource($repository);
    }

    public function count(
        Request $request,
        GetRepositoryGithubCountAction $action,
    ): GithubRepositoryCountResource {
        return new GithubRepositoryCountResource(
            $action->handle($request->user())
        );
    }

    public function branches(
        GetBranchRequest $request,
        GetBranchAction $action,
    ): JsonResponse {
        $branches = $action->handle(
            $request->user(),
            $request->toData(),
        );

        return response()->json([
            'data' => $branches,
        ]);
    }
}
