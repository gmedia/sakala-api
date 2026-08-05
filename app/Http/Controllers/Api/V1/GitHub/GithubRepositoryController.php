<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\GitHub;

use App\Actions\GitHub\GetGithubRepositoryCollectionAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\GitHub\GithubResource;
use App\Http\Resources\Api\V1\GitHub\GithubRepositoryCollectionResource;
use App\Http\Requests\Api\V1\GitHub\GetGithubRequest;
use App\Http\Requests\Api\V1\GitHub\ValidateUrlRequest;
use App\Actions\GitHub\ValidateUrlRepositoryAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Actions\GitHub\GetGithubRepositoryCountAction;
use App\Http\Resources\Api\V1\GitHub\GithubRepositoryCountResource;

final class GithubRepositoryController extends Controller
{
    public function index(
        GetGithubRequest $request,
        GetGithubRepositoryCollectionAction $action,
    ): GithubRepositoryCollectionResource {
        $repositories = $action->handle(
            $request->user(),
            $request->toData()
        );

        return new GithubRepositoryCollectionResource($repositories);
    }

    public function validate(
        ValidateUrlRequest $request,
        ValidateUrlRepositoryAction $action,
    ): GithubResource {

        $repository = $action->handle(
            $request->toData()
        );
        return new GithubResource($repository);
    }

    public function count(
        Request $request,
        GetGithubRepositoryCountAction $action,
    ): GithubRepositoryCountResource {
        return new GithubRepositoryCountResource(
            $action->handle($request->user())
        );
    }
}
