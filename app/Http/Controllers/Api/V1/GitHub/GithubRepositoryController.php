<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\GitHub;

use App\Actions\GitHub\GetGithubRepositoryCollectionAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\GitHub\GithubResource;
use App\Http\Requests\Api\V1\GitHub\GetGithubRequest;
use App\Http\Requests\Api\V1\GitHub\ValidateUrlRequest;
use App\Actions\GitHub\ValidateUrlRepositoryAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class GithubRepositoryController extends Controller
{
    public function index(
        GetGithubRequest $request,
        GetGithubRepositoryCollectionAction $action,
    ): AnonymousResourceCollection {
        $repositories = $action->handle(
            $request->user(),
            $request->toData()
        );

        return GithubResource::collection($repositories);
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
}
