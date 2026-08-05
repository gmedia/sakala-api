<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Data\GitHub\GithubRepositoryData;
use App\Data\GitHub\GetGithubRepositoryData;
use App\Data\GitHub\GithubRepoCollectionResponseData;
use App\Support\GitHub\RepositoryParser;
use Illuminate\Validation\ValidationException;
use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class GithubRepositoryService
{
    
    public function __construct(
        private RepositoryParser $repositoryParser,
    ) {}

    private function getAccessToken(User $user): string
    {
        $accessToken = OAuthAccount::query($user)
            ->where('user_id', $user->id)
            ->value('access_token');

        if ($accessToken === null || empty($accessToken)) {
            throw new RuntimeException('GitHub access token not found for the user.');
        }

        return $accessToken;
    }

    private function toRepository(array $repositories): GithubRepositoryData
    {
        return new GithubRepositoryData(
            id: $repositories['id'],
            name: $repositories['name'],
            fullName: $repositories['full_name'],
            cloneUrl: $repositories['clone_url'],
            defaultBranch: $repositories['default_branch'],
            pushedAt: $repositories['pushed_at'],
            private: $repositories['private'],
        );
    }

    private function github(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->acceptJson();
    }

    private function resolveLastPage(Response $response): int
    {
        $linkHeader = $response->header('Link');

        if ($linkHeader === null) {
            return 1;
        }

        if (preg_match('/[?&]page=(\d+)[^>]*>; rel="last"/', $linkHeader, $matches)) {
            return (int) $matches[1];
        }

        if (str_contains($linkHeader, 'rel="next"')) {
            return 2;
        }

        return 1;
    }

    public function getUserRepositories(User $user, GetGithubRepositoryData $data): GithubRepoCollectionResponseData
    {
        $accessToken = $this->getAccessToken($user);

        if (empty($accessToken) || $accessToken === null) {
            return new GithubRepoCollectionResponseData(
                repositories: [],
                page: $data->page,
                perPage: $data->perPage,
            );
        }

        $response = $this->github($accessToken)->get(
            'https://api.github.com/user/repos',
            [
                'visibility' => 'all',
                'affiliation' => 'owner',
                'sort' => 'updated',
                'page' => $data->page,
                'per_page' => $data->perPage,
            ]
        );
        $response->throw();

        $repositories = collect($response->json())
            ->map(fn (array $repository) => $this->toRepository($repository))
            ->when(
                filled($data->search),
                fn ($collection) => $collection->filter(fn ($repo) =>
                    str_contains(
                        mb_strtolower($repo->name),
                        mb_strtolower($data->search)
                    )
                )
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

    public function getRepositoryByUrl(
        string $url,
    ): GithubRepositoryData {
        try {
            $repository = $this->repositoryParser->parse($url);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'repository_url' => [
                    'The repository url is not a valid GitHub repository URL.',
                ],
            ]);
        }

        $response = Http::acceptJson()->get(
            "https://api.github.com/repos/{$repository->repository_full_name}",
        );

        if ($response->status() === 404) {
            throw ValidationException::withMessages([
                'repository_url' => [
                    'Repository not found.',
                ],
            ]);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Failed to retrieve repository from GitHub.',
            );
        }

        return $this->toRepository($response->json());
    }

    public function countRepositories(User $user): int
    {
        $accessToken = $this->getAccessToken($user);

        if (empty($accessToken) || $accessToken === null) {
            return 0;
        }

        $response = $this->github($accessToken)->get(
            'https://api.github.com/user',
            [
                'visibility' => 'all',
                'affiliation' => 'owner',
                'sort' => 'updated',
                'per_page' => 1,
            ]
        );

        $response->throw();

        return (int) (
            $response->json('public_repos', 0)
            + $response->json('total_private_repos', 0)
        );
    }
}
