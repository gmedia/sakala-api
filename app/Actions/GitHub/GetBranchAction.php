<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Data\GitHub\GithubRepositoryUrlData;
use App\Models\User;
use App\Services\GitHub\GithubBranchService;

final class GetBranchAction
{
    public function __construct(
        private readonly GithubBranchService $service,
    ) {}

    /**
     * @return array<int, string>
     */
    public function handle(
        User $user,
        GithubRepositoryUrlData $data,
    ): array {
        return $this->service->getBranches(
            $user,
            $data->repositoryUrl,
        );
    }
}
