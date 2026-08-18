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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateDeploymentAction
{
    public function __construct(
        private readonly GithubBranchService $githubBranchService,
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

        $commit = $this->githubBranchService->getBranchCommit(
            user: $user,
            repositoryFullName: $project->repository_full_name,
            branch: $data->branch,
        );

        $deployment = DB::transaction(function () use ($project, $user, $data, $commit) {
            $project = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence = (int) $project->deployments()->max('sequence') + 1;

            return Deployment::create([
                'project_id' => $project->id,
                'requested_by' => $user->id,
                'sequence' => $sequence,
                'status' => DeploymentStatus::Queued,
                'trigger' => DeploymentTrigger::Manual,

                'branch' => $data->branch,
                'commit_sha' => $commit['sha'],
                'commit_message' => $commit['message'],
            ]);
        });

        SimulatedDeploymentJob::dispatch($deployment);

        return $deployment;
    }
}
