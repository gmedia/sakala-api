<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Data\GitHub\GetGithubRepositoryData;
use App\Data\GitHub\GithubRepoCollectionResponseData;
use App\Models\User;
use App\Services\GitHub\GithubRepositoryService;

final class GetRepositoryGithubCollectionAction
{
    public function __construct(
        private readonly GithubRepositoryService $service,
    ) {}

    public function handle(User $user, GetGithubRepositoryData $data): GithubRepoCollectionResponseData
    {
        return $this->service->getUserRepositories($user, $data);
    }
}
