<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Models\User;
use App\Services\GitHub\GithubRepositoryService;

final class GetRepositoryGithubCountAction
{
    public function __construct(
        private readonly GithubRepositoryService $githubRepositoryService,
    ) {}

    public function handle(User $user): int
    {
        return $this->githubRepositoryService->countRepositories($user);
    }
}
