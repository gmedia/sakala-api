<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Data\Database\AttachedProjectDeployOutcomeData;
use App\Enums\DatabaseRedeploySkipReason;
use App\Enums\NotificationCategory;
use App\Jobs\Database\DeployAfterDatabaseReadyJob;
use App\Models\AgentNode;
use App\Models\DatabaseAttachment;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Services\Notification\UserNotifier;
use App\Support\Database\DatabaseAudit;
use Illuminate\Support\Facades\DB;

/**
 * Deployments asked for with `deploy_when_ready`, kept on the attachment of a
 * database created for its project. Each request is resolved exactly once,
 * under the attachment row lock: with the deployment the queued
 * DeployAfterDatabaseReadyJob created once the database is ready, or with a
 * skip reason, `database_provisioning_failed` when the creation failed or
 * expired. Detaching the database deletes the request with the attachment.
 */
final class DatabaseDeployRequests
{
    private const PROVISIONING_FAILED_TITLE = 'Database gagal dibuat';

    private const PROVISIONING_FAILED_MESSAGE = '%s: database %s gagal dibuat, jadi proyek ini belum di-deploy. Lepas database itu dari proyek, buat yang baru, lalu deploy lagi.';

    public function __construct(
        private readonly UserNotifier $notifier,
    ) {}

    /**
     * Inside the transaction that marks the database ready, under its row
     * lock: queue the deployments once it commits, when any were asked for.
     */
    public function queueAfterReady(ManagedDatabase $lockedDatabase): void
    {
        if (DatabaseAttachment::query()->where('database_id', $lockedDatabase->id)->deployPending()->exists()) {
            DeployAfterDatabaseReadyJob::dispatch($lockedDatabase->id)->afterCommit();
        }
    }

    /**
     * Inside the transaction that marks the database failed, under its row
     * lock: nothing will ever be ready to deploy against, so every pending
     * request is skipped, and the person who asked hears about it while they
     * can still act on the project (otherwise its owners and members).
     * `$actor` is the node that reported the failure, or the expiry sweep.
     */
    public function skipAfterFailedProvisioning(AgentNode|string $actor, ManagedDatabase $lockedDatabase): void
    {
        $attachments = DatabaseAttachment::query()
            ->where('database_id', $lockedDatabase->id)
            ->deployPending()
            ->with(['project.workspace', 'deployRequester'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($attachments as $attachment) {
            $this->record($actor, $attachment, $lockedDatabase, AttachedProjectDeployOutcomeData::skipped(DatabaseRedeploySkipReason::DatabaseProvisioningFailed));

            if ($attachment->project !== null) {
                $this->notifier->sendToProject(
                    $attachment->project,
                    NotificationCategory::ProjectIssue,
                    self::PROVISIONING_FAILED_TITLE,
                    sprintf(self::PROVISIONING_FAILED_MESSAGE, $attachment->project->name, $lockedDatabase->name),
                    preferred: $attachment->deployRequester,
                );
            }
        }
    }

    /**
     * Records what the job did for one request, unless it was resolved or
     * detached meanwhile. The deployment carries an idempotency key per
     * attachment, so a second run that got this far found the same one.
     */
    public function resolve(DatabaseAttachment $attachment, ManagedDatabase $database, AttachedProjectDeployOutcomeData $outcome): void
    {
        DB::transaction(function () use ($attachment, $database, $outcome): void {
            $locked = DatabaseAttachment::query()->whereKey($attachment->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isDeployPending()) {
                return;
            }

            $this->record($attachment->deployRequester, $locked, $database, $outcome);
        });
    }

    private function record(User|AgentNode|string|null $actor, DatabaseAttachment $locked, ManagedDatabase $database, AttachedProjectDeployOutcomeData $outcome): void
    {
        $locked->update([
            'deploy_deployment_id' => $outcome->deploymentId,
            'deploy_skipped_reason' => $outcome->skippedReason?->value,
            'deploy_resolved_at' => now(),
        ]);

        $metadata = ['project_id' => $locked->project_id, 'attachment_id' => $locked->id];

        if ($outcome->deploymentId !== null) {
            DatabaseAudit::record($actor, DatabaseAudit::PROJECT_DEPLOY_STARTED, $database, [...$metadata, 'deployment_id' => $outcome->deploymentId]);
        } else {
            DatabaseAudit::record($actor, DatabaseAudit::PROJECT_DEPLOY_SKIPPED, $database, [...$metadata, 'reason' => $outcome->skippedReason?->value]);
        }
    }
}
