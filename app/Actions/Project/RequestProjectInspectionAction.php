<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Agent\ProjectInspectionResultData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectInspectionStatus;
use App\Enums\RepositoryAccess;
use App\Exceptions\GitHub\GithubRateLimitedException;
use App\Models\AgentCommand;
use App\Models\Project;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Services\GitHub\GithubBranchService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
    /** @var list<AgentCommandStatus> */
    private const RETRYABLE = [
        AgentCommandStatus::Failed,
        AgentCommandStatus::Cancelled,
        AgentCommandStatus::Expired,
    ];

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
        } catch (GithubRateLimitedException) {
            $this->unavailable($project, 'github_rate_limited');

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

            // The same commit inspected from another directory is another
            // question, so it gets its own key and its own attempts.
            if ($locked->root_directory !== null) {
                $idempotencyKey .= ':root:'.sha1($locked->root_directory);
            }

            // The newest attempt for this commit and directory decides: one in
            // flight or done is reused, one that ended badly can be tried again.
            $existing = AgentCommand::query()
                ->where('project_id', $locked->id)
                ->where('type', AgentCommandType::InspectProject)
                ->where('idempotency_key', 'like', "inspect:{$locked->id}:{$commit['sha']}%")
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->first(static fn (AgentCommand $command): bool => ($command->payload['root_directory'] ?? null) === $locked->root_directory);

            if ($existing !== null && ! in_array($existing->status, self::RETRYABLE, true) && ! $this->predatesExposedPorts($existing)) {
                if ($existing->status === AgentCommandStatus::Succeeded && $locked->inspection !== null) {
                    $result = ProjectInspectionResultData::fromArray($existing->result);

                    $locked->update([
                        'inspection_status' => ProjectInspectionStatus::Succeeded,
                        'inspection_error_code' => null,
                        // Moving to another directory and back cleared the port
                        // this directory exposed; the reused result still has it.
                        ...($result->exposedPorts === null ? [] : ['detected_port' => $result->detectedPort()]),
                    ]);
                }

                return $existing;
            }

            if ($existing !== null) {
                $idempotencyKey .= ':'.Str::uuid();
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
                    // Optional addition to protocol revision 4; absent unless set.
                    ...($locked->root_directory !== null ? ['root_directory' => $locked->root_directory] : []),
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

    /**
     * A finished Dockerfile inspection from an agent older than `exposed_ports`
     * never reports a port, so reusing it would keep a project that predates
     * the field on 3000 until its next commit. Asking again lets a newer agent
     * answer; an older one simply answers the same way.
     */
    private function predatesExposedPorts(AgentCommand $command): bool
    {
        $result = $command->result;

        return $command->status === AgentCommandStatus::Succeeded
            && is_array($result)
            && ($result['dockerfile_found'] ?? false) === true
            && ! array_key_exists('exposed_ports', $result);
    }

    private function unavailable(Project $project, string $code): void
    {
        $project->update([
            'inspection_status' => ProjectInspectionStatus::Unavailable,
            'inspection_error_code' => $code,
        ]);
    }
}
