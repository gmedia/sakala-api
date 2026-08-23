<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Models\Project;
use App\Models\User;
use App\Support\GitHub\RepositoryParser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class GithubBranchService
{
    public function __construct(
        private readonly GithubAPIClient $githubClient,
        private readonly GithubInstallationTokenService $installationTokens,
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

        $result = [];
        $page = 1;

        do {
            $response = $this->githubClient->get(
                "/repos/{$repositoryFullName}/branches",
                null,
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

    /**
     * @return array{sha: string, message: string}
     */
    public function getBranchCommit(
        Project $project,
        string $branch,
    ): array {
        $installation = $project->github_installation_id === null ? null : $project->githubInstallation;
        if ($project->github_installation_id !== null && $installation === null) {
            $this->throwRepositoryAccessRemoved();
        }

        $response = $this->githubClient->get(
            "/repos/{$project->repository_full_name}/commits",
            $installation === null ? null : $this->installationTokens->for($installation),
            [
                'sha' => $branch,
                'per_page' => 1,
            ]
        );

        if ($response->status() === 404) {
            if ($installation !== null) {
                $this->throwRepositoryAccessRemoved();
            }

            throw ValidationException::withMessages([
                'branch' => [
                    'The selected branch does not exist or is inaccessible.',
                ],
            ]);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Failed to retrieve branch commit from GitHub.',
            );
        }
        $commits = $response->json();

        if (! is_array($commits) || $commits === []) {
            throw ValidationException::withMessages([
                'branch' => [
                    'The selected branch has no commits.',
                ],
            ]);
        }

        return [
            'sha' => $commits[0]['sha'],
            'message' => trim(
                explode("\n", $commits[0]['commit']['message'], 2)[0],
            ),
        ];
    }

    private function throwRepositoryAccessRemoved(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'GitHub App no longer has access to this repository. Reconnect GitHub or choose another repository.',
        ], 409));
    }
}
