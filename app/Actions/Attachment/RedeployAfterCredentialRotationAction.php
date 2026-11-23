<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\Data\Attachment\AttachedProjectDeployOutcomeData;
use App\Data\Deployment\CreateDeploymentData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AttachedRedeploySkipReason;
use App\Models\AgentCommand;
use App\Models\ManagedBucket;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\User;
use App\Services\Attachment\AttachedProjectDeployer;
use App\Support\Attachment\CredentialRotation;
use App\Support\Bucket\BucketAudit;
use App\Support\Database\DatabaseAudit;
use Illuminate\Support\Facades\DB;

/**
 * After a credential rotation that asked for it took effect (a database
 * password or a bucket key), redeploys every project attached to that
 * resource that has a live deployment, so its containers get the new
 * credential. Runs from a queued job after the command's transaction, because
 * creating a deployment may ask GitHub. Each project goes through the normal
 * deployment path as whoever asked for the rotation, with every limit; a
 * project that cannot be redeployed is skipped with a reason and keeps
 * `redeploy_required`. The rotation is complete either way.
 *
 * A redeploy runs the commit that is live, not the branch head, so
 * nobody ships unreviewed commits by rotating a password; with nothing
 * build-affecting changed it usually reuses the live image
 * (PlanDeploymentBuildAction). When the live deployment is a rollback (or a
 * redeploy of one), it runs with the same recorded settings. Running again does
 * nothing: the outcome is recorded once, and each deployment has an
 * idempotency key per rotation.
 */
final class RedeployAfterCredentialRotationAction
{
    public function __construct(
        private readonly AttachedProjectDeployer $deployer,
    ) {}

    public function handle(string $commandId): void
    {
        $command = AgentCommand::query()->find($commandId);

        if ($command === null
            || ! CredentialRotation::isRotation($command)
            || $command->status !== AgentCommandStatus::Succeeded
            || ! CredentialRotation::redeploysAttached($command)
            || CredentialRotation::redeploysQueued($command)) {
            return;
        }

        $resource = $this->resourceOf($command);
        $requester = CredentialRotation::requester($command);
        $redeploys = [];

        if ($resource !== null && $resource->status->isProvisioned()) {
            $projects = $resource->projects()->with(['activeDeployment.settingsSource', 'workspace'])->orderBy('name')->orderBy('projects.id')->get();

            foreach ($projects as $project) {
                $redeploys[] = ['project_id' => $project->id, ...$this->redeploy($command, $project, $requester)];
            }
        }

        $this->record($command, $resource, $redeploys);
    }

    /** The database or bucket whose credential the command rotated, if it still exists. */
    private function resourceOf(AgentCommand $command): ManagedDatabase|ManagedBucket|null
    {
        return match ($command->type) {
            AgentCommandType::RotateDatabasePassword => $command->database_id === null ? null : ManagedDatabase::query()->find($command->database_id),
            AgentCommandType::RotateBucketKey => $command->bucket_id === null ? null : ManagedBucket::query()->find($command->bucket_id),
            default => null,
        };
    }

    /** @return array{deployment_id: string|null, skipped_reason: string|null} */
    private function redeploy(AgentCommand $command, Project $project, ?User $requester): array
    {
        $active = $project->active_deployment_id === null ? null : $project->activeDeployment;

        if ($active === null) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::NoActiveDeployment)->toArray();
        }

        // The live commit, unless the project has moved to another branch
        // since; then the branch head, as a manual redeploy would. A live
        // rollback keeps the settings it ran with, so its image is reused
        // instead of rebuilt with the project's newer ones.
        $sameBranch = $active->branch === $project->branch && $active->commit_sha !== null;

        return $this->deployer->deploy($project, $requester, new CreateDeploymentData(
            branch: $project->branch,
            idempotencyKey: CredentialRotation::idempotencyKey($command),
            trigger: CredentialRotation::trigger($command),
            commit_sha: $sameBranch ? $active->commit_sha : null,
            commit_message: $sameBranch ? $active->commit_message : null,
            settingsSource: $sameBranch ? $active->settingsSource : null,
        ))->toArray();
    }

    /** @param  list<array{project_id: string, deployment_id: string|null, skipped_reason: string|null}>  $redeploys */
    private function record(AgentCommand $command, ManagedDatabase|ManagedBucket|null $resource, array $redeploys): void
    {
        DB::transaction(function () use ($command, $resource, $redeploys): void {
            /** @var AgentCommand $locked */
            $locked = AgentCommand::query()->whereKey($command->id)->lockForUpdate()->firstOrFail();

            if (CredentialRotation::redeploysQueued($locked)) {
                return;
            }

            $locked->update([
                'request_context' => [
                    ...($locked->request_context ?? []),
                    CredentialRotation::REDEPLOYS_QUEUED_AT_KEY => now()->toAtomString(),
                    CredentialRotation::REDEPLOYS_KEY => $redeploys,
                ],
            ]);

            $metadata = ['command_id' => $locked->id, 'redeploys' => $redeploys];
            $requester = CredentialRotation::requester($locked);

            match (true) {
                $resource instanceof ManagedDatabase => DatabaseAudit::record($requester, DatabaseAudit::PASSWORD_ROTATION_REDEPLOYS_QUEUED, $resource, $metadata),
                $resource instanceof ManagedBucket => BucketAudit::record($requester, BucketAudit::KEY_ROTATION_REDEPLOYS_QUEUED, $resource, $metadata),
                default => null,
            };
        });
    }
}
