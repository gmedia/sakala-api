<?php

namespace App\Actions\GitHub;

use App\Models\User;
use App\Services\GitHub\GithubRepositoryService;
use App\Data\GitHub\GetGithubRepositoryData;
use App\Data\GitHub\GithubRepoCollectionResponseData;

final class GetGithubRepositoryCountAction
{
    public function __construct(
        private GithubRepositoryService $githubRepositoryService,
    ) {}

    public function handle(User $user): int
    {
        return $this->githubRepositoryService->countRepositories($user);
    }
}
