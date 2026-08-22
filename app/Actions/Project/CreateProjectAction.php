<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\CreateProjectData;
use App\Models\Project;
use App\Models\User;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\GitHub\RepositoryParser;
use Illuminate\Support\Facades\DB;

final class CreateProjectAction
{
    public function __construct(
        protected GenerateProjectIdentity $generateIdentity,
        protected RepositoryParser $repositoryParser,
        protected PilotRuntimeLimitService $runtimeLimitService,
    ) {}

    public function handle(User $user, CreateProjectData $data): Project
    {
        return DB::transaction(function () use ($user, $data): Project {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->runtimeLimitService->checkProjectCreationLimit($user);

            $projectIdentity = $this->generateIdentity->handle($data->name);

            $parsedRepository = $this->repositoryParser->parse(
                $data->repository_url
            );

            return Project::create([
                'user_id' => $user->id,
                'name' => $data->name,
                'slug' => $projectIdentity->slug,
                'repository_provider' => $parsedRepository->repository_provider,
                'repository_url' => $parsedRepository->repository_url,
                'repository_full_name' => $parsedRepository->repository_full_name,
                'branch' => $data->branch,
                'default_domain' => $projectIdentity->defaultDomain,
            ]);
        });
    }
}
