<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Data\GitHub\GithubRepositoryData;
use App\Data\GitHub\GetGithubRepositoryData;
use App\Support\GitHub\RepositoryParser;
use Illuminate\Validation\ValidationException;
use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class GithubRepositoryService
{
    
    public function __construct(
        private RepositoryParser $repositoryParser,
    ) {}

    public function getUserRepositories(User $user, GetGithubRepositoryData $data): array
    {
        $account = OAuthAccount::query($user)
            ->where('user_id', $user->id)
            ->first();

        if ($account === null || empty($account->access_token)) {
            return [];
        }

        $response = Http::withToken($account->access_token)
            ->acceptJson()
            ->get('https://api.github.com/user/repos', [
                'visibility' => 'all',
                'affiliation' => 'owner',
                'sort' => 'updated',
                'page' => $data->page,
                'per_page' => $data->perPage,
            ]);

        $response->throw();

        return collect($response->json())
            ->map(fn (array $repository) => new GithubRepositoryData(
                id: $repository['id'],
                name: $repository['name'],
                fullName: $repository['full_name'],
                cloneUrl: $repository['clone_url'],
                defaultBranch: $repository['default_branch'],
                pushedAt: $repository['pushed_at'],
                private: $repository['private'],
            ))
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

        return new GithubRepositoryData(
            id: $response->json('id'),
            name: $response->json('name'),
            fullName: $response->json('full_name'),
            cloneUrl: $response->json('clone_url'),
            defaultBranch: $response->json('default_branch'),
            pushedAt: $response->json('pushed_at'),
            private: $response->json('private'),
        );
    }
}
