<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Data\GitHub\GithubRepositoryData;

final class GithubRepositoryMapper
{
    /**
     * Convert GitHub API repository response to application DTO.
     *
     * @param  array<string, mixed>  $repository
     */
    public function toData(array $repository): GithubRepositoryData
    {
        return new GithubRepositoryData(
            id: (string) $repository['id'],
            name: $repository['name'],
            fullName: $repository['full_name'],
            cloneUrl: $repository['clone_url'],
            defaultBranch: $repository['default_branch'],
            pushedAt: $repository['pushed_at'],
            private: $repository['private'],
        );
    }
}
