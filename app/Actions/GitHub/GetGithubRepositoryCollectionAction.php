<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Models\User;
use App\Services\GitHub\GithubRepositoryService;
use App\Data\GitHub\GetGithubRepositoryData;

final class GetGithubRepositoryCollectionAction
{
    public function __construct(
        protected GithubRepositoryService $service,
    ) {}

    public function handle(User $user, GetGithubRepositoryData $data): array
    {
        return $this->service->getUserRepositories($user, $data);
    }
}
