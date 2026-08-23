<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\CreateProjectData;
use App\Enums\GithubRepositorySource;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Models\User;
use App\Services\GitHub\GithubInstallationService;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\GitHub\RepositoryParser;
use Illuminate\Support\Facades\DB;

final class CreateProjectAction
{
    public function __construct(
        protected GenerateProjectIdentity $generateIdentity,
        protected RepositoryParser $repositoryParser,
        protected PilotRuntimeLimitService $runtimeLimitService,
        private readonly GithubInstallationService $githubInstallationService,
    ) {}

    public function handle(User $user, CreateProjectData $data): Project
    {
        return DB::transaction(function () use ($user, $data): Project {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->runtimeLimitService->checkProjectCreationLimit($user);

            $projectIdentity = $this->generateIdentity->handle($data->name);

            $attributes = match ($data->repositorySource) {
                GithubRepositorySource::PublicUrl => $this->publicRepositoryAttributes($data),
                GithubRepositorySource::GithubInstallation => $this->installationRepositoryAttributes($user, $data),
            };

            return Project::create([
                'user_id' => $user->id,
                'name' => $data->name,
                'slug' => $projectIdentity->slug,
                ...$attributes,
                'branch' => $data->branch,
                'default_domain' => $projectIdentity->defaultDomain,
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function publicRepositoryAttributes(CreateProjectData $data): array
    {
        $parsed = $this->repositoryParser->parse($data->repositoryUrl ?? '');

        return [
            'repository_provider' => $parsed->repository_provider,
            'repository_url' => $parsed->repository_url,
            'repository_full_name' => $parsed->repository_full_name,
        ];
    }

    /** @return array<string, mixed> */
    private function installationRepositoryAttributes(User $user, CreateProjectData $data): array
    {
        $installation = GithubInstallation::query()->whereKey($data->githubInstallationId)
            ->whereHas('users', fn ($query) => $query->whereKey($user->id))
            ->firstOrFail();
        $repository = $this->githubInstallationService->repositoryForUser($user, $installation, $data->githubRepositoryId ?? 0);

        return [
            'repository_provider' => 'github',
            'repository_url' => (string) $repository['clone_url'],
            'repository_full_name' => (string) $repository['full_name'],
            'github_installation_id' => $installation->id,
            'github_repository_id' => (int) $repository['id'],
        ];
    }
}
