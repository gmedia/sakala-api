<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Admin\RecordUsageSignalAction;
use App\Actions\Workspace\CreatePersonalWorkspaceAction;
use App\Data\Project\CreateProjectData;
use App\Enums\GithubRepositorySource;
use App\Enums\UsageSignalType;
use App\Exceptions\Runtime\ProjectLimitExceededException;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\GitHub\GithubInstallationService;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\GitHub\RepositoryParser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class CreateProjectAction
{
    /**
     * @var array<string, string>
     */
    private const LIMIT_EXCEPTION_MAP = [
        ProjectLimitExceededException::class => 'max_projects_per_user',
    ];

    public function __construct(
        protected GenerateProjectIdentity $generateIdentity,
        protected RepositoryParser $repositoryParser,
        protected PilotRuntimeLimitService $runtimeLimitService,
        private readonly GithubInstallationService $githubInstallationService,
        private readonly RecordUsageSignalAction $recordAction,
        private readonly RequestProjectInspectionAction $requestInspection,
        private readonly CreatePersonalWorkspaceAction $createPersonalWorkspace,
    ) {}

    /**
     * The project belongs to the named workspace, or to the creator's personal
     * workspace, and counts against that workspace's limits. The user row is
     * locked first, as account deletion does, then the workspace row, so the
     * count and the membership cannot change before the insert commits.
     */
    public function handle(User $user, CreateProjectData $data): Project
    {
        $workspaceId = $data->workspaceId ?? $this->createPersonalWorkspace->handle($user)->id;
        $scope = 'user';

        try {
            $project = DB::transaction(function () use ($user, $data, $workspaceId, &$scope): Project {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                /** @var Workspace $workspace */
                $workspace = Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();
                $scope = $workspace->isPersonal() ? 'user' : 'workspace';

                // The request checked this too; under the lock it cannot be stale.
                if ($workspace->roleOf($user)?->managesProjects() !== true) {
                    throw new AuthorizationException;
                }

                $this->runtimeLimitService->checkProjectCreationLimit($user, $workspace);

                $projectIdentity = $this->generateIdentity->handle($data->name);

                $attributes = match ($data->repositorySource) {
                    GithubRepositorySource::PublicUrl => $this->publicRepositoryAttributes($data),
                    GithubRepositorySource::GithubInstallation => $this->installationRepositoryAttributes($user, $data),
                };

                return Project::create([
                    'user_id' => $user->id,
                    'workspace_id' => $workspace->id,
                    'name' => $data->name,
                    'slug' => $projectIdentity->slug,
                    ...$attributes,
                    'branch' => $data->branch,
                    'default_domain' => $projectIdentity->defaultDomain,
                    'auto_deploy' => $data->autoDeploy,
                    ...$data->buildConfiguration,
                ]);
            });

            // Outside the creation transaction: the preview talks to GitHub
            // and must never hold the user or workspace lock or fail the creation.
            $this->requestInspection->handle($project);

            return $project->refresh();
        } catch (ProjectLimitExceededException $e) {
            $limitName = self::LIMIT_EXCEPTION_MAP[get_class($e)];
            $this->recordAction->handle(
                type: UsageSignalType::RejectedLimits,
                count: 1,
                scope: $scope,
                tags: ['limit_name' => $limitName],
            );

            throw $e;
        }
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
