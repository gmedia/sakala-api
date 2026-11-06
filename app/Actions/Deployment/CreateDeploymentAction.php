<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Actions\Admin\RecordUsageSignalAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RepositoryAccess;
use App\Enums\RepositoryAccessState;
use App\Enums\UsageSignalType;
use App\Exceptions\GitHub\GithubRateLimitedException;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\RepositoryAccessRevokedException;
use App\Exceptions\Runtime\ResourceLimitExceededException;
use App\Jobs\Deployment\SimulatedDeploymentJob;
use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Services\GitHub\GithubBranchService;
use App\Services\GitHub\GithubRepositoryAccessService;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

        if ($existing === null) {
            return null;
        }

        $requestedResources = $data->requested_resources?->toArray();

        if ($existing->branch !== $data->branch || $existing->requested_resources !== $requestedResources) {
            throw new ConflictHttpException(
                'Idempotency key ini sudah dipakai untuk deployment lain. Pakai key baru.'
            );
        }

        return $existing;
    }

    private const LIMIT_EXCEPTION_MAP = [
        ActiveDeploymentLimitExceededException::class => [
            'user' => 'max_active_deployments_per_user',
            'project' => 'max_active_deployments_per_project',
        ],
        ResourceLimitExceededException::class => [
            'memory_mb' => 'max_memory_mb',
            'cpu_millis' => 'max_cpu_millis',
            'pids_limit' => 'max_pids_limit',
        ],
    ];

    public function __construct(
        private readonly GithubBranchService $githubBranchService,
        private readonly PilotRuntimeLimitService $runtimeLimitService,
        private readonly RecordUsageSignalAction $recordAction,
        private readonly AgentNodeSchedulerService $scheduler,
        private readonly GithubRepositoryAccessService $repositoryAccess,
    ) {}

    /**
     * While GitHub rate limits us a known commit is a safe target: the deployment
     * shows exactly which commit it runs. GithubPublicReader already falls back to
     * its last answer; this covers an empty cache and installation projects.
     *
     * @return array{sha: string, message: string|null}
     */
    private function resolveBranchHead(Project $project, string $branch): array
    {
        try {
            return $this->githubBranchService->getBranchCommit($project, $branch, revalidate: true);
        } catch (GithubRateLimitedException $exception) {
            $known = Deployment::query()
                ->where('project_id', $project->id)
                ->where('branch', $branch)
                ->whereNotNull('commit_sha')
                ->orderByDesc('sequence')
                ->first();

            if ($known === null) {
                throw $exception;
            }

            return ['sha' => (string) $known->commit_sha, 'message' => $known->commit_message];
        }
    }

    public function handle(
        Project $project,
        User $user,
        CreateDeploymentData $data
    ): Deployment {
        if ($data->branch !== $project->branch) {
            throw ValidationException::withMessages([
                'branch' => [
                    'Branch yang dipilih berbeda dengan branch proyek. Pilih branch yang terdaftar di proyek ini.',
                ],
            ]);
        }

        $existing = $this->findExistingDeployment($project, $user, $data);

        if ($existing !== null) {
            return $existing;
        }

        // Early and clear for the owner. The lease checks again, and is also what
        // decides when GitHub cannot answer here.
        if ($project->github_installation_id !== null
            && $project->github_repository_id !== null
            && $this->repositoryAccess->forOwner($project) === RepositoryAccessState::Revoked) {
            throw new RepositoryAccessRevokedException;
        }

        // A push webhook already names the commit; only a manual deployment has
        // to ask GitHub for the branch head.
        $commit = $data->commit_sha !== null
            ? ['sha' => $data->commit_sha, 'message' => $data->commit_message]
            : $this->resolveBranchHead($project, $data->branch);

        $created = false;

        try {
            $deployment = DB::transaction(function () use (
                $project,
                $user,
                $data,
                $commit,
                &$created
            ) {
                // Lock user record to ensure atomic active deployment quota
                // across different projects
                User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

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

                if ($lockedProject->status === ProjectStatus::Suspended) {
                    throw new ProjectSuspendedException;
                }

                // Enforce active deployment limits under user and project lock
                $this->runtimeLimitService->checkActiveDeploymentLimit(
                    $user,
                    $lockedProject,
                );

                // Resolve effective runtime limits
                $effectiveLimits = $this->runtimeLimitService->resolveEffectiveLimits(
                    $data->requested_resources,
                    $user,
                );

                $sequence = (int) $lockedProject->deployments()->max('sequence') + 1;

                $created = true;

                // Pin the target node now so the secret-bearing payload is
                // only ever materialised for one authenticated node. With no
                // eligible node the command waits unassigned for a later
                // assignment sweep or heartbeat.
                $node = $this->scheduler->selectNodeFor(
                    AgentCommandType::DeployProject,
                    $lockedProject,
                );

                $deployment = Deployment::create([
                    'project_id' => $lockedProject->id,
                    'requested_by' => $user->id,
                    'agent_node_id' => $node?->id,
                    'idempotency_key' => $data->idempotencyKey,
                    'sequence' => $sequence,
                    'status' => DeploymentStatus::Queued,
                    'trigger' => $data->trigger,
                    'branch' => $data->branch,
                    'commit_sha' => $commit['sha'],
                    'commit_message' => $commit['message'],
                    // Fixed at creation: a later port change only affects the next deployment.
                    'container_port' => $lockedProject->effectivePort(),
                    'requested_resources' => $data->requested_resources?->toArray(),
                    'effective_resources' => $effectiveLimits->toArray(),
                ]);

                // Create pending DeployProject agent command with explicit
                // limits contract
                $commandPayload = [
                    'repository_url' => $lockedProject->repository_url,
                    'commit_sha' => $deployment->commit_sha,
                    'repository_access' => $lockedProject->github_installation_id === null
                        ? RepositoryAccess::Public->value
                        : RepositoryAccess::TemporaryCredential->value,
                    'domain' => $lockedProject->default_domain,
                    'container_port' => $deployment->container_port,
                    'builder' => 'auto',
                    'environment' => $lockedProject->environmentVariables
                        ->mapWithKeys(fn (EnvironmentVariable $env): array => [
                            $env->key => (string) (
                                $env->getRawOriginal('encrypted_value')
                                ?? Crypt::encryptString(
                                    (string) $env->encrypted_value
                                )
                            ),
                        ])
                        ->all(),
                    'resources' => $effectiveLimits->toResourcesArray(),
                    'timeouts' => $effectiveLimits->timeouts->toArray(),
                    'log_bounds' => $effectiveLimits->log_bounds->toArray(),
                    // Optional additions to protocol revision 4; absent unless set.
                    ...array_filter([
                        'build_command' => $lockedProject->build_command,
                        'start_command' => $lockedProject->start_command,
                        'root_directory' => $lockedProject->root_directory,
                    ], static fn (?string $value): bool => $value !== null),
                ];

                AgentCommand::create([
                    'project_id' => $lockedProject->id,
                    'deployment_id' => $deployment->id,
                    'agent_node_id' => $node?->id,
                    'type' => AgentCommandType::DeployProject,
                    'status' => AgentCommandStatus::Pending,
                    'payload' => $commandPayload,
                    'idempotency_key' => (string) Str::uuid(),
                    'available_at' => now(),
                    // `command_timeout_seconds` is the execution deadline the
                    // agent applies after claim, not a queue TTL. A deploy
                    // that is waiting for a node must not silently expire;
                    // deterministic expiry/recovery arrives with lease handling.
                    'expires_at' => null,
                ]);

                return $deployment;
            });

            if ($created && config('sakala.deployments.simulate') === true) {
                SimulatedDeploymentJob::dispatch($deployment);
            }

            return $deployment;
        } catch (ActiveDeploymentLimitExceededException $e) {
            $limitName = self::LIMIT_EXCEPTION_MAP[get_class($e)][
                $e->scope
            ] ?? 'unknown';
            $this->recordAction->handle(
                type: UsageSignalType::RejectedLimits,
                count: 1,
                scope: $e->scope,
                tags: ['limit_name' => $limitName],
            );

            throw $e;
        } catch (ResourceLimitExceededException $e) {
            $limitName = self::LIMIT_EXCEPTION_MAP[get_class($e)][
                $e->resource
            ] ?? 'unknown';
            $this->recordAction->handle(
                type: UsageSignalType::RejectedLimits,
                count: 1,
                scope: 'user',
                tags: ['limit_name' => $limitName],
            );

            throw $e;
        }
    }
}
