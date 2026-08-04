<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Data\GitHub\GithubRepositoryData;
use App\Data\GitHub\ValidateGithubRepositoryData;
use App\Services\GitHub\GithubRepositoryService;

final class ValidateUrlRepositoryAction
{
    public function __construct(
        private GithubRepositoryService $githubRepositoryService,
    ) {}

    public function handle(
        ValidateGithubRepositoryData $data,
    ): GithubRepositoryData {
        return $this->githubRepositoryService
            ->getRepositoryByUrl(
                $data->repositoryUrl,
            );
    }
}