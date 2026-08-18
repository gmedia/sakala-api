<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Data\Deployment\CreateDeploymentData;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Jobs\Deployment\SimulatedDeploymentJob;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\GitHub\GithubBranchService;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateDeploymentAction
{
    private function findExistingDeployment(
        Project $project,
        User $user,
        CreateDeploymentData $data
    ): ?Deployment {
        if ($data->idempotencyKey === null) {
            return null;
        }

        $existing = Deployment::query()
            ->where('project_id', $project->id)
            ->where('requested_by', $user->id)
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();

        if ($existing !== null && $existing->branch !== $data->branch) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => [
                    'The idempotency key has already been used for a different deployment.',
                ],
            ]);
        }

        return $existing;
    }

    public function __construct(
        private readonly GithubBranchService $githubBranchService,
        private readonly PilotRuntimeLimitService $runtimeLimitService,
    ) {}

    public function handle(
        Project $project,
        User $user,
        CreateDeploymentData $data
    ): Deployment {
        if ($data->branch !== $project->branch) {
            throw ValidationException::withMessages([
                'branch' => [
                    'The selected branch does not match the project branch.',
                ],
            ]);
        }

        $existing = $this->findExistingDeployment($project, $user, $data);

        if ($existing !== null) {
            return $existing;
        }

        $commit = $this->githubBranchService->getBranchCommit(
            user: $user,
            repositoryFullName: $project->repository_full_name,
            branch: $data->branch,
        );

        $created = false;

        $deployment = DB::transaction(function () use ($project, $user, $data, $commit, &$created) {
            /** @var Project $lockedProject */
            $lockedProject = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $this->findExistingDeployment(
                project: $lockedProject,
                user: $user,
                data: $data,
            );

            if ($existing !== null) {
                return $existing;
            }

            // Enforce active deployment limits under project lock
            $this->runtimeLimitService->checkActiveDeploymentLimit($user, $lockedProject);

            // Resolve effective runtime limits
            $effectiveLimits = $this->runtimeLimitService->resolveEffectiveLimits($data->requested_resources, $user);

            $sequence = (int) $lockedProject->deployments()->max('sequence') + 1;

            $created = true;

            return Deployment::create([
                'project_id' => $lockedProject->id,
                'requested_by' => $user->id,
                'idempotency_key' => $data->idempotencyKey,
                'sequence' => $sequence,
                'status' => DeploymentStatus::Queued,
                'trigger' => $data->trigger,
                'branch' => $data->branch,
                'commit_sha' => $commit['sha'],
                'commit_message' => $commit['message'],
                'requested_resources' => $data->requested_resources?->toArray(),
                'effective_resources' => $effectiveLimits->toResourcesArray(),
            ]);
        });

        if ($created) {
            SimulatedDeploymentJob::dispatch($deployment);
        }

        return $deployment;
    }
}
