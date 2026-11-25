<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Actions\Admin\RecordUsageSignalAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Data\Deployment\DeploymentSettingsData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RepositoryAccess;
use App\Enums\RepositoryAccessState;
use App\Enums\RollbackIneligibleReason;
use App\Enums\UsageSignalType;
use App\Exceptions\GitHub\GithubRateLimitedException;
use App\Exceptions\Runtime\ActiveContainerLimitExceededException;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\BuildRateLimitExceededException;
use App\Exceptions\Runtime\DeploymentNotRollbackTargetException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\RepositoryAccessRevokedException;
use App\Exceptions\Runtime\ResourceLimitExceededException;
use App\Exceptions\Runtime\WorkerContainerLimitExceededException;
use App\Jobs\Deployment\SimulatedDeploymentJob;
use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Services\Bucket\BucketConnectionEnvironment;
use App\Services\Database\DatabaseConnectionEnvironment;
use App\Services\Deployment\DeploymentInspectionHold;
use App\Services\GitHub\GithubBranchService;
use App\Services\GitHub\GithubRepositoryAccessService;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\Database\TransactionRetry;
use App\Support\Deployment\DeploymentRollbackEligibility;
use App\Support\GitHub\CommitMessage;
use App\Support\Project\DeploymentConfigFingerprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CreateDeploymentAction
{
    /** Usage signal scope of the limits that count across a workspace. */
    private const WORKSPACE_SCOPE = 'workspace';

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

        if ($existing->branch !== $data->branch
            || $existing->requested_resources !== $requestedResources
            || $existing->rollback_target_deployment_id !== $data->rollbackTarget?->id) {
            throw new ConflictHttpException(
                'Idempotency key ini sudah dipakai untuk deployment lain. Pakai key baru.'
            );
        }

        return $existing;
    }

    private const LIMIT_EXCEPTION_MAP = [
        ActiveDeploymentLimitExceededException::class => [
            'user' => 'max_active_deployments_per_user',
            'workspace' => 'max_active_deployments_per_workspace',
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
        private readonly DatabaseConnectionEnvironment $databaseEnvironment,
        private readonly BucketConnectionEnvironment $bucketEnvironment,
        private readonly PlanDeploymentBuildAction $planBuild,
        private readonly RecordRollbackAction $recordRollback,
        private readonly DeploymentInspectionHold $inspectionHold,
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
        // A rollback answers a branch change with its own reason, under the lock.
        if ($data->rollbackTarget === null && $data->branch !== $project->branch) {
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

        // Early, so a refused rollback never asks GitHub; checked again under the lock.
        $rollbackRefusal = $data->rollbackTarget === null
            ? null
            : DeploymentRollbackEligibility::reason($data->rollbackTarget, $project);

        if ($rollbackRefusal !== null) {
            throw new DeploymentNotRollbackTargetException($rollbackRefusal);
        }

        // Early and clear for whoever deploys. The lease checks again, and is
        // also what decides when GitHub cannot answer here.
        if ($project->github_installation_id !== null
            && $project->github_repository_id !== null
            && $this->repositoryAccess->forProject($project) === RepositoryAccessState::Revoked) {
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
                // A deadlock retry runs this again from the start.
                $created = false;

                // The workspace row first: its quotas span all of its projects,
                // so two deployments in one workspace are counted one at a time.
                if ($project->workspace_id !== null) {
                    Workspace::query()
                        ->whereKey($project->workspace_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

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

                // Decided under the lock: the active deployment and the
                // branch cannot change before the rollback is created.
                if ($data->rollbackTarget !== null) {
                    $reason = DeploymentRollbackEligibility::reason($data->rollbackTarget, $lockedProject);

                    if ($reason !== null) {
                        throw new DeploymentNotRollbackTargetException($reason);
                    }
                }

                // What the deployment runs with: the project's settings, or
                // what an earlier deployment recorded (a rollback, or a
                // redeploy of one). Without a record a rollback is refused;
                // any other deployment runs with the project's settings.
                $recorded = $data->settingsSource === null ? null : DeploymentSettingsData::recordedBy($data->settingsSource);

                if ($data->rollbackTarget !== null && $recorded === null) {
                    throw new DeploymentNotRollbackTargetException(RollbackIneligibleReason::SettingsUnavailable);
                }

                $settings = $recorded ?? DeploymentSettingsData::fromProject($lockedProject);

                // Enforce the workspace's limits under the workspace and project
                // lock; worker containers count like replicas (ADR-022).
                $this->runtimeLimitService->checkActiveDeploymentLimit(
                    $user,
                    $lockedProject,
                    $settings->workerContainers(),
                );

                // Resolve effective runtime limits
                $effectiveLimits = $this->runtimeLimitService->resolveEffectiveLimits(
                    $data->requested_resources,
                    $lockedProject->workspace,
                );

                $replicas = $this->runtimeLimitService->replicasFor($lockedProject);

                $sequence = (int) $lockedProject->deployments()->max('sequence') + 1;

                $created = true;

                // Pin the target node now so the secret-bearing payload is
                // only ever materialised for one authenticated node. With no
                // eligible node the command waits unassigned for a later
                // assignment sweep or heartbeat.
                // Only a node of the project's region that runs every replica,
                // and the release command when there is one, is selected: an
                // older one would run one container, or skip the release
                // command, and report success. Without one the command waits,
                // unless no node could ever take it.
                $releaseCommand = $settings->hasReleaseCommand();
                $workerProcesses = $settings->hasWorkerProcesses();

                $node = $this->scheduler->selectNodeFor(
                    AgentCommandType::DeployProject,
                    $lockedProject,
                    $replicas,
                    $releaseCommand,
                    $workerProcesses,
                );

                $this->scheduler->ensurePlaceable($lockedProject, $node, $replicas, $releaseCommand, $workerProcesses);

                // Loaded once: the build fingerprint and the payload must see
                // the same variables. Recorded settings keep their recorded
                // build fingerprint: it describes the image they ran.
                $variables = $lockedProject->environmentVariables;
                $buildFingerprint = $recorded !== null
                    ? $data->settingsSource->build_fingerprint
                    : DeploymentConfigFingerprint::forBuild($lockedProject, $variables);

                $buildPlan = $this->planBuild->handle(
                    lockedProject: $lockedProject,
                    node: $node,
                    commitSha: $commit['sha'],
                    buildFingerprint: $buildFingerprint,
                    forceBuild: $data->forceBuild,
                    rollbackTarget: $data->rollbackTarget,
                );

                // Only a build counts against the build rate: a deployment
                // that reuses the live image builds nothing. Still under the
                // workspace lock, and nothing has been written yet.
                if (! $buildPlan->reuses()) {
                    $this->runtimeLimitService->checkBuildRateLimit(
                        $user,
                        $lockedProject,
                    );
                }

                // A project from before environment fingerprints gets one now,
                // under the lock, so it and this deployment agree from here on.
                if ($lockedProject->environment_fingerprint === null) {
                    $lockedProject->refreshEnvironmentFingerprint();
                    $lockedProject->save();
                }

                // The attached database's connection variables, built now and
                // never stored as the project's own; a variable the project sets
                // itself keeps its value. Refuses the deployment while the
                // database node has not reported where projects reach it.
                $databaseEnvironment = $this->databaseEnvironment->forProject($lockedProject);
                // The same for the attached bucket (ADR-021): refused while it
                // is not created yet or its node has not reported the endpoint.
                $bucketEnvironment = $this->bucketEnvironment->forProject($lockedProject);

                $deployment = Deployment::create([
                    'project_id' => $lockedProject->id,
                    'requested_by' => $user->id,
                    'agent_node_id' => $node?->id,
                    'idempotency_key' => $data->idempotencyKey,
                    'sequence' => $sequence,
                    'status' => DeploymentStatus::Queued,
                    'trigger' => $data->trigger,
                    'triggered_by_name' => $data->triggeredByName,
                    'rollback_target_deployment_id' => $data->rollbackTarget?->id,
                    'settings_source_deployment_id' => $recorded === null ? null : $data->settingsSource->id,
                    'branch' => $data->branch,
                    'commit_sha' => $commit['sha'],
                    'commit_message' => CommitMessage::subject($commit['message']),
                    // Fixed at creation: a later port change only affects the next
                    // deployment. While the inspection that may find the port is
                    // still running, the command is held and gets the port then.
                    // Recorded settings carry their own port, so a rollback (or a
                    // redeploy of one) is never held.
                    'container_port' => $settings->containerPort,
                    'awaiting_inspection_until' => $recorded === null && $this->inspectionHold->shouldHold($lockedProject)
                        ? $this->inspectionHold->deadline()
                        : null,
                    // Fixed at creation too: a later change applies from the next deployment.
                    'replicas' => $replicas,
                    // And the worker containers next to them (ADR-022).
                    'worker_containers' => $settings->workerContainers(),
                    // What `redeploy_required` compares with the project later,
                    // taken from the same locked row as the payload below.
                    'config_fingerprint' => $lockedProject->deploymentConfigFingerprint(),
                    'build_fingerprint' => $buildFingerprint,
                    'build_mode' => $buildPlan->mode,
                    'image_source_deployment_id' => $buildPlan->imageSourceDeploymentId,
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
                    'environment' => [
                        ...$variables
                            ->mapWithKeys(fn (EnvironmentVariable $env): array => [
                                $env->key => (string) (
                                    $env->getRawOriginal('encrypted_value')
                                    ?? Crypt::encryptString(
                                        (string) $env->encrypted_value
                                    )
                                ),
                            ])
                            ->all(),
                        ...array_map(
                            static fn (string $value): string => Crypt::encryptString($value),
                            $databaseEnvironment,
                        ),
                        ...array_map(
                            static fn (string $value): string => Crypt::encryptString($value),
                            $bucketEnvironment,
                        ),
                    ],
                    'resources' => $effectiveLimits->toResourcesArray(),
                    'timeouts' => $effectiveLimits->timeouts->toArray(),
                    'log_bounds' => $effectiveLimits->log_bounds->toArray(),
                    // Optional additions to protocol revision 4; absent unless
                    // set. `release_command` only goes to a node with the
                    // `release-command` capability (selected above).
                    ...$settings->toPayload(),
                    // Optional addition to protocol revision 4, only above one,
                    // and only to a node with the `replicas` capability.
                    ...($replicas > Project::DEFAULT_REPLICAS ? ['replicas' => $replicas] : []),
                    // Optional addition to protocol revision 4, only for a
                    // reuse plan, which only targets a node with the
                    // `image-reuse` capability: the deployment that built the
                    // image. The agent derives the image name and checks its
                    // labels itself.
                    ...($buildPlan->reuses() ? ['reuse_image_from_deployment_id' => $buildPlan->imageSourceDeploymentId] : []),
                    // Optional addition to protocol revision 4 (ADR-022), only
                    // with worker definitions, and only to a node with the
                    // `worker-processes` capability (selected above).
                    ...$settings->processesPayload(),
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

                if ($data->rollbackTarget !== null) {
                    $this->recordRollback->handle($lockedProject, $deployment, $data->rollbackTarget, $user);
                }

                return $deployment;
            }, TransactionRetry::ATTEMPTS);

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
        } catch (WorkerContainerLimitExceededException $e) {
            $this->recordAction->handle(
                type: UsageSignalType::RejectedLimits,
                count: 1,
                scope: self::WORKSPACE_SCOPE,
                tags: ['limit_name' => WorkerContainerLimitExceededException::LIMIT_NAME],
            );

            throw $e;
        } catch (ActiveContainerLimitExceededException $e) {
            $this->recordAction->handle(
                type: UsageSignalType::RejectedLimits,
                count: 1,
                scope: self::WORKSPACE_SCOPE,
                tags: ['limit_name' => ActiveContainerLimitExceededException::LIMIT_NAME],
            );

            throw $e;
        } catch (BuildRateLimitExceededException $e) {
            $this->recordAction->handle(
                type: UsageSignalType::RejectedLimits,
                count: 1,
                scope: self::WORKSPACE_SCOPE,
                tags: ['limit_name' => BuildRateLimitExceededException::LIMIT_NAME],
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
