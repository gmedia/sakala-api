<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Jobs\Deployment\SimulatedDeploymentJob;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateDeploymentAction
{
    /**
     * @param array{
     *     branch: string,
     *     commit_sha?: string|null,
     *     commit_message?: string|null,
     * } $data
     */
    public function handle(
        Project $project,
        User $user,
        array $data
    ): Deployment {
        $deployment = DB::transaction(function () use ($project, $user, $data) {
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

                'branch' => $data['branch'],
                'commit_sha' => $data['commit_sha'] ?? null,
                'commit_message' => $data['commit_message'] ?? null,
            ]);
        });

        SimulatedDeploymentJob::dispatch($deployment);

        return $deployment;
    }
}
