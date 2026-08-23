<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Data\GitHub\GetGithubRepositoryData;
use App\Data\GitHub\GithubRepoCollectionResponseData;
use App\Data\GitHub\GithubRepositoryData;
use App\Models\User;
use App\Support\GitHub\RepositoryParser;
use Illuminate\Http\Client\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class GithubRepositoryService
{
    public function __construct(
        private readonly GithubRepositoryMapper $githubRepositoryMapper,
        private readonly GithubOAuth $githubOAuth,
        private readonly GithubAPIClient $githubClient,
        private readonly RepositoryParser $repositoryParser,
    ) {}

    /**
     * Parse and normalize a GitHub repository URL.
     *
     * Example:
     * https://github.com/laravel/laravel.git
     * → laravel/laravel
     */
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
     * Resolve the last page from GitHub pagination Link header.
     */
    private function resolveLastPage(Response $response): int
    {
        $linkHeader = $response->header('Link');

        if (blank($linkHeader)) {
            return 1;
        }

        if (preg_match(
            '/[?&]page=(\d+)[^>]*>;\s*rel="last"/',
            $linkHeader,
            $matches,
        )) {
            return (int) $matches[1];
        }

        return 1;
    }

    /**
     * Get repositories owned by the authenticated GitHub user
     * using GitHub's normal repository endpoint.
     */
    private function getUserRepositoriesPaginated(
        string $accessToken,
        GetGithubRepositoryData $data,
    ): GithubRepoCollectionResponseData {
        $response = $this->githubClient->get(
            '/user/repos',
            $accessToken,
            [
                'visibility' => 'all',
                'affiliation' => 'owner',
                'sort' => 'updated',
                'direction' => 'desc',
                'page' => $data->page,
                'per_page' => $data->perPage,
            ],
        );

        $response->throw();

        $repositoriesResponse = $response->json();

        if (! is_array($repositoriesResponse)) {
            throw new RuntimeException(
                'Invalid repository response from GitHub.',
            );
        }

        /** @var array<int, array<string, mixed>> $repositoriesResponse */
        $repositories = collect($repositoriesResponse)
            ->map(
                fn (array $repository): GithubRepositoryData => $this->githubRepositoryMapper->toData($repository),
            )
            ->values()
            ->all();

        $lastPage = $this->resolveLastPage($response);

        return new GithubRepoCollectionResponseData(
            repositories: $repositories,
            page: $data->page,
            perPage: $data->perPage,
            lastPage: $lastPage,
            hasNextPage: $lastPage > $data->page,
            hasPreviousPage: $data->page > 1,
        );
    }

    /**
     * Search repositories owned by the authenticated GitHub user.
     */
    private function searchUserRepositories(
        User $user,
        string $accessToken,
        GetGithubRepositoryData $data,
    ): GithubRepoCollectionResponseData {
        $username = $this->githubOAuth->getUsername($user);

        $response = $this->githubClient->get(
            '/search/repositories',
            $accessToken,
            [
                'q' => sprintf(
                    'user:%s %s',
                    $username,
                    $data->search,
                ),
                'sort' => 'updated',
                'order' => 'desc',
                'page' => $data->page,
                'per_page' => $data->perPage,
            ],
        );

        $response->throw();

        $items = $response->json('items', []);

        if (! is_array($items)) {
            throw new RuntimeException(
                'Invalid repository response from GitHub.',
            );
        }

        /** @var array<int, array<string, mixed>> $items */
        $repositories = collect($items)
            ->map(
                fn (array $repository): GithubRepositoryData => $this->githubRepositoryMapper->toData($repository),
            )
            ->values()
            ->all();

        $total = (int) $response->json('total_count', 0);

        $lastPage = $total === 0
            ? 1
            : (int) ceil($total / $data->perPage);

        return new GithubRepoCollectionResponseData(
            repositories: $repositories,
            page: $data->page,
            perPage: $data->perPage,
            lastPage: $lastPage,
            hasNextPage: $lastPage > $data->page,
            hasPreviousPage: $data->page > 1,
        );
    }

    /**
     * Get repositories owned by the authenticated GitHub user.
     *
     * Requires GitHub OAuth.
     */
    public function getUserRepositories(
        User $user,
        GetGithubRepositoryData $data,
    ): GithubRepoCollectionResponseData {
        $accessToken = $this->githubOAuth->getAccessToken($user);

        if (filled($data->search)) {
            return $this->searchUserRepositories(
                $user,
                $accessToken,
                $data,
            );
        }

        return $this->getUserRepositoriesPaginated(
            $accessToken,
            $data,
        );
    }

    /**
     * Get repository information from a GitHub URL.
     *
     * Supports:
     * - Public repository without GitHub OAuth.
     * - Private repository when the user has connected GitHub.
     */
    public function getRepositoryByUrl(
        User $user,
        string $url,
    ): GithubRepositoryData {
        return $this->getPublicRepositoryByUrl($url);
    }

    public function getPublicRepositoryByUrl(string $url): GithubRepositoryData
    {

        $repositoryFullName = $this->parseRepository($url);

        $response = $this->githubClient->get(
            "/repos/{$repositoryFullName}",
            null,
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
                'Failed to retrieve repository from GitHub.',
            );
        }

        $repository = $response->json();

        if (! is_array($repository)) {
            throw new RuntimeException(
                'Invalid repository response from GitHub.',
            );
        }

        if (($repository['private'] ?? false) === true) {
            throw ValidationException::withMessages([
                'repository_url' => ['Private repositories must be selected from a connected GitHub installation.'],
            ]);
        }

        /** @var array<string, mixed> $repository */
        return $this->githubRepositoryMapper->toData($repository);
    }

    /**
     * Count repositories owned by the authenticated GitHub user.
     *
     * Requires GitHub OAuth.
     */
    public function countRepositories(User $user): int
    {
        $accessToken = $this->githubOAuth->getAccessToken($user);

        $response = $this->githubClient->get(
            '/user',
            $accessToken,
        );

        $response->throw();

        return (int) (
            $response->json('public_repos', 0)
            + $response->json('owned_private_repos', 0)
        );
    }
}
