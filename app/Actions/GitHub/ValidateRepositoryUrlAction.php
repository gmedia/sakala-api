<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Data\GitHub\GithubRepositoryData;
use App\Data\GitHub\GithubRepositoryUrlData;
use App\Models\User;
use App\Services\GitHub\GithubRepositoryService;

final class ValidateRepositoryUrlAction
{
    public function __construct(
        private readonly GithubRepositoryService $githubRepositoryService,
    ) {}

    public function handle(
        User $user,
        GithubRepositoryUrlData $data
    ): GithubRepositoryData {
        return $this->githubRepositoryService
            ->getRepositoryByUrl(
                $user,
                $data->repositoryUrl,
            );
    }
}
