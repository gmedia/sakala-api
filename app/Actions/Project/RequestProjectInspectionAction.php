<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectInspectionStatus;
use App\Enums\RepositoryAccess;
use App\Models\AgentCommand;
use App\Models\Project;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Services\GitHub\GithubBranchService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ask a runtime node to inspect the project's current branch head so the
 * console can show a stack preview. Inspection never blocks project
 * creation: when the branch head cannot be resolved the project simply has
 * no preview (`unavailable`) and the reason is recorded.
 */
final class RequestProjectInspectionAction
{
    public function __construct(
        private readonly GithubBranchService $branches,
        private readonly AgentNodeSchedulerService $scheduler,
    ) {}

    public function handle(Project $project): ?AgentCommand
    {
        try {
            $commit = $this->branches->getBranchCommit($project, $project->branch);
        } catch (ValidationException) {
            $this->unavailable($project, 'branch_not_found');

            return null;
        } catch (HttpResponseException) {
            $this->unavailable($project, 'repository_access_denied');

            return null;
        } catch (Throwable $exception) {
            Log::warning('Project inspection could not resolve the branch head.', [
                'project_id' => $project->id,
                'error' => $exception->getMessage(),
            ]);
            $this->unavailable($project, 'github_unavailable');

            return null;
        }

        return DB::transaction(function () use ($project, $commit): AgentCommand {
            /** @var Project $locked */
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $idempotencyKey = "inspect:{$locked->id}:{$commit['sha']}";

            $existing = AgentCommand::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            $node = $this->scheduler->selectNodeFor(AgentCommandType::InspectProject, $locked);

            $command = AgentCommand::create([
                'project_id' => $locked->id,
                'deployment_id' => null,
                'agent_node_id' => $node?->id,
                'type' => AgentCommandType::InspectProject,
                'status' => AgentCommandStatus::Pending,
                'payload' => [
                    'repository_url' => $locked->repository_url,
                    'commit_sha' => $commit['sha'],
                    'repository_access' => $locked->github_installation_id === null
                        ? RepositoryAccess::Public->value
                        : RepositoryAccess::TemporaryCredential->value,
                ],
                'idempotency_key' => $idempotencyKey,
                'available_at' => now(),
                'expires_at' => null,
            ]);

            $locked->update([
                'inspection_status' => ProjectInspectionStatus::Pending,
                'inspection_error_code' => null,
            ]);

            return $command;
        });
    }

    private function unavailable(Project $project, string $code): void
    {
        $project->update([
            'inspection_status' => ProjectInspectionStatus::Unavailable,
            'inspection_error_code' => $code,
        ]);
    }
}
