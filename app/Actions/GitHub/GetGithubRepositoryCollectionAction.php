<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Models\User;
use App\Services\GitHub\GithubRepositoryService;
use App\Data\GitHub\GetGithubRepositoryData;
use App\Data\GitHub\GithubRepoCollectionResponseData;

final class GetGithubRepositoryCollectionAction
{
    public function __construct(
        protected GithubRepositoryService $service,
    ) {}

    public function handle(User $user, GetGithubRepositoryData $data): GithubRepoCollectionResponseData
    {
        return $this->service->getUserRepositories($user, $data);
    }
}
