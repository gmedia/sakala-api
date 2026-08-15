<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Models\User;
use App\Support\GitHub\RepositoryParser;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class GithubBranchService
{
    public function __construct(
        private readonly GithubAPIClient $githubClient,
        private readonly GithubOAuth $githubOAuth,
        private readonly RepositoryParser $repositoryParser,
    ) {}

    private function parseRepository(string $url): string
    {
        try {
            return $this->repositoryParser
                ->parse($url)
                ->repository_full_name;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'repository_url' => [
                    'The repository url is not a valid GitHub repository URL.',
                ],
            ]);
        }
    }

    /**
     * Get branches from a GitHub repository URL.
     *
     * Supports:
     * - Public repository without GitHub OAuth.
     * - Private repository when the user has connected GitHub.
     *
     * @return list<string>
     */
    public function getBranches(
        User $user,
        string $repositoryUrl,
    ): array {
        $repositoryFullName = $this->parseRepository($repositoryUrl);

        $accessToken = $this->githubOAuth
            ->getOptionalAccessToken($user);

        $result = [];
        $page = 1;

        do {
            $response = $this->githubClient->get(
                "/repos/{$repositoryFullName}/branches",
                $accessToken,
                [
                    'page' => $page,
                    'per_page' => 100,
                ]
            );

            if ($response->status() === 404) {
                throw ValidationException::withMessages([
                    'repository_url' => [
                        'Repository not found or inaccessible.',
                    ],
                ]);
            }

            if ($response->failed()) {
                throw new RuntimeException(
                    'Failed to retrieve branches from GitHub.',
                );
            }

            $branches = $response->json();

            if (! is_array($branches)) {
                throw new RuntimeException(
                    'Invalid branches response from GitHub.',
                );
            }

            /** @var array<int, array{name: string}> $branches */
            foreach ($branches as $branch) {
                $result[] = $branch['name'];
            }

            $page++;
        } while ($this->githubClient->hasNextPage($response));

        return $result;
    }
}
