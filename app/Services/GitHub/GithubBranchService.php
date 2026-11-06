<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Exceptions\GitHub\GithubRateLimitedException;
use App\Models\GithubInstallation;
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
        private readonly GithubPublicReader $publicReader,
    ) {}

    /**
     * Anonymous GitHub requests cannot see private repositories, but the server
     * token might. Nothing about a private repository may reach a user through
     * the public flow, so it reads as not found.
     */
    private function publicRepositoryIsReadable(string $repositoryFullName): bool
    {
        if (! $this->publicReader->authenticated()) {
            return true;
        }

        $repository = $this->publicReader->get(
            "/repos/{$repositoryFullName}",
            [],
            GithubPublicReader::TTL_REPOSITORY_SECONDS,
        );

        return ! $repository->notFound()
            && is_array($repository->json)
            && ($repository->json['private'] ?? true) === false;
    }

    private function parseRepository(string $url): string
    {
        try {
            return $this->repositoryParser
                ->parse($url)
                ->repository_full_name;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'repository_url' => [
                    'URL repository harus berupa URL GitHub, misalnya https://github.com/pemilik/nama-repo.',
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

        if (! $this->publicRepositoryIsReadable($repositoryFullName)) {
            $this->throwRepositoryNotFound();
        }

        $result = [];
        $page = 1;

        do {
            $response = $this->publicReader->get(
                "/repos/{$repositoryFullName}/branches",
                ['page' => $page, 'per_page' => 100],
                GithubPublicReader::TTL_BRANCHES_SECONDS,
            );

            if ($response->notFound()) {
                $this->throwRepositoryNotFound();
            }

            if ($response->failed()) {
                throw new RuntimeException(
                    'Failed to retrieve branches from GitHub.',
                );
            }

            $branches = $response->json;

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
        } while ($response->hasNextPage);

        return $result;
    }

    /**
     * @param  bool  $revalidate  Ask GitHub even within the cache window, because a
     *                            deployment right after a push must get that push.
     * @return array{sha: string, message: string}
     *
     * @throws GithubRateLimitedException when limited and no earlier head is cached
     * @throws ValidationException when the branch is missing or has no commits
     * @throws HttpResponseException when the GitHub App lost access
     */
    public function getBranchCommit(
        Project $project,
        string $branch,
        bool $revalidate = false,
    ): array {
        $installation = $project->github_installation_id === null ? null : $project->githubInstallation;
        if ($project->github_installation_id !== null && $installation === null) {
            $this->throwRepositoryAccessRemoved();
        }

        $commits = $installation === null
            ? $this->publicBranchCommits($project, $branch, $revalidate)
            : $this->installationBranchCommits($project, $installation, $branch);

        if (! is_array($commits) || $commits === []) {
            throw ValidationException::withMessages([
                'branch' => [
                    'Branch yang dipilih belum punya commit. Push minimal satu commit, lalu coba lagi.',
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

    private function publicBranchCommits(Project $project, string $branch, bool $revalidate): mixed
    {
        if (! $this->publicRepositoryIsReadable((string) $project->repository_full_name)) {
            $this->throwBranchNotFound();
        }

        $response = $this->publicReader->get(
            "/repos/{$project->repository_full_name}/commits",
            ['sha' => $branch, 'per_page' => 1],
            GithubPublicReader::TTL_BRANCH_HEAD_SECONDS,
            $revalidate,
        );

        if ($response->notFound()) {
            $this->throwBranchNotFound();
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Failed to retrieve branch commit from GitHub.',
            );
        }

        return $response->json;
    }

    private function installationBranchCommits(Project $project, GithubInstallation $installation, string $branch): mixed
    {
        $response = $this->githubClient->get(
            "/repos/{$project->repository_full_name}/commits",
            $this->installationTokens->for($installation),
            [
                'sha' => $branch,
                'per_page' => 1,
            ]
        );

        if ($response->status() === 404) {
            $this->throwRepositoryAccessRemoved();
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Failed to retrieve branch commit from GitHub.',
            );
        }

        return $response->json();
    }

    private function throwRepositoryNotFound(): never
    {
        throw ValidationException::withMessages([
            'repository_url' => [
                'Repository tidak ditemukan atau tidak bisa diakses Sakala. Periksa lagi URL-nya dan pastikan repository bersifat publik.',
            ],
        ]);
    }

    private function throwBranchNotFound(): never
    {
        throw ValidationException::withMessages([
            'branch' => [
                'Branch yang dipilih tidak ditemukan di repository. Periksa nama branch-nya atau pilih branch lain.',
            ],
        ]);
    }

    private function throwRepositoryAccessRemoved(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'GitHub App no longer has access to this repository. Reconnect GitHub or choose another repository.',
        ], 409));
    }
}
