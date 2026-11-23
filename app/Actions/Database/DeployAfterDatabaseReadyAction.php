<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Data\Deployment\CreateDeploymentData;
use App\Enums\DeploymentTrigger;
use App\Models\DatabaseAttachment;
use App\Models\ManagedDatabase;
use App\Services\Attachment\AttachedProjectDeployer;
use App\Services\Database\DatabaseDeployRequests;

/**
 * Once a database created for a project with `deploy_when_ready` is ready,
 * deploys that project: the branch head, as the person who asked, through
 * the normal deployment path with every limit, and `trigger`
 * `database_ready`, so the history says why it started without a click.
 * Runs from a queued job after the node's report committed, because
 * creating a deployment may ask GitHub.
 *
 * At most one deployment per request: the request is resolved once under its
 * row lock, and the deployment's idempotency key is per attachment, so a
 * second run creates nothing. A project that cannot be deployed is skipped
 * with a reason and can be deployed by hand.
 */
final class DeployAfterDatabaseReadyAction
{
    public function __construct(
        private readonly AttachedProjectDeployer $deployer,
        private readonly DatabaseDeployRequests $requests,
    ) {}

    public function handle(string $databaseId): void
    {
        $database = ManagedDatabase::query()->find($databaseId);

        // Still being created, or failed: the failure resolved its requests.
        if ($database === null || ! $database->status->isProvisioned()) {
            return;
        }

        $attachments = DatabaseAttachment::query()
            ->where('database_id', $database->id)
            ->deployPending()
            ->with(['project.workspace', 'deployRequester'])
            ->orderBy('id')
            ->get();

        foreach ($attachments as $attachment) {
            $project = $attachment->project;

            if ($project === null) {
                continue;
            }

            $outcome = $this->deployer->deploy($project, $attachment->deployRequester, new CreateDeploymentData(
                branch: $project->branch,
                idempotencyKey: $attachment->deployIdempotencyKey(),
                trigger: DeploymentTrigger::DatabaseReady,
            ));

            $this->requests->resolve($attachment, $database, $outcome);
        }
    }
}
