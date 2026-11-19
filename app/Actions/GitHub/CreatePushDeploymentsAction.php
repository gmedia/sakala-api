<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Actions\Deployment\CreateDeploymentAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Data\GitHub\SkippedPushData;
use App\Data\Notification\ProjectAlertMailData;
use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\GithubInstallationStatus;
use App\Enums\NotificationCategory;
use App\Enums\ProjectAlert;
use App\Enums\PushSkipReason;
use App\Enums\WorkspaceRole;
use App\Exceptions\Database\DatabaseEndpointUnavailableException;
use App\Exceptions\Database\DatabaseNotReadyException;
use App\Exceptions\Runtime\ActiveContainerLimitExceededException;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\BuildRateLimitExceededException;
use App\Exceptions\Runtime\ProjectReleaseCommandUnsupportedException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\RepositoryAccessRevokedException;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\Notification\UserNotifier;
use App\Support\GitHub\CommitMessage;
use App\Support\GitHub\PushChangedPaths;
use App\Support\Project\MonorepoPathOwnership;
use Illuminate\Database\Eloquent\Collection;

/**
 * Turns a verified GitHub push into deployments for the projects that opted
 * in. The delivery ID is the idempotency key, on top of the delivery record the
 * webhook controller already holds, so a redelivery creates nothing new. A push
 * never bypasses a limit: an attempt over the limit, or on a suspended project,
 * is skipped, audited, and reported to the workspace's owners and members.
 *
 * In a monorepo, a push deploys only the projects it concerns: one with a
 * changed file in its root directory, or in no project's folder (a shared
 * file). Any doubt deploys: a payload that may not list every file, a project
 * without a root directory, or one whose last deployment did not ship. Such a
 * skipped push is audited as `project.push_skipped`, shown in the project's
 * history, and notifies no one.
 */
final class CreatePushDeploymentsAction
{
    private const NO_COMMIT = '0000000000000000000000000000000000000000';

    /** Audit action of a push that auto-deploy could not deploy (a limit, access, suspension, ...). */
    public const AUTO_DEPLOY_SKIPPED_ACTION = 'project.auto_deploy_skipped';

    /** Audit reason of a push skipped by the workspace's build rate limit. */
    private const SKIP_BUILD_RATE_LIMIT = 'build_rate_limit';

    /** Audit reason of a push skipped because no node runs the project's release command. */
    private const SKIP_RELEASE_COMMAND_UNSUPPORTED = 'release_command_unsupported';

    /** Audit reason of a push skipped because the attached database's node has not reported its endpoint. */
    public const SKIP_DATABASE_ENDPOINT_UNAVAILABLE = 'database_endpoint_unavailable';

    /** Audit reason of a push skipped because the attached database is still being created, or failed to be. */
    public const SKIP_DATABASE_NOT_READY = 'database_not_ready';

    public function __construct(
        private readonly CreateDeploymentAction $createDeployment,
        private readonly UserNotifier $notifier,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(array $payload, string $deliveryId): void
    {
        $branch = $this->branch($payload);
        $commit = is_string($payload['after'] ?? null) ? $payload['after'] : '';
        $repository = $payload['repository'] ?? null;
        $installation = $payload['installation'] ?? null;

        if ($branch === null
            || ($payload['deleted'] ?? false) === true
            || $commit === self::NO_COMMIT
            || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1
            || ! is_array($repository) || ! is_int($repository['id'] ?? null)
            || ! is_array($installation) || ! is_int($installation['id'] ?? null)) {
            return;
        }

        $headCommit = is_array($payload['head_commit'] ?? null) ? $payload['head_commit'] : [];
        $message = is_string($headCommit['message'] ?? null) ? $headCommit['message'] : null;

        $projects = Project::query()
            ->with(['user', 'workspace', 'workspace.personalUser', 'githubInstallation'])
            ->where('auto_deploy', true)
            ->where('github_repository_id', $repository['id'])
            ->where('branch', $branch)
            ->whereHas('githubInstallation', function ($query) use ($installation): void {
                $query->where('github_installation_id', $installation['id'])
                    ->where('status', GithubInstallationStatus::Active);
            })
            ->get();

        $changes = PushChangedPaths::fromPayload($payload);
        $ownership = $changes === null ? [] : $this->ownershipByWorkspace($projects, $repository['id'], $branch);

        foreach ($projects as $project) {
            $baseline = $changes === null
                ? null
                : $this->baselineIfUnconcerned($project, $ownership[$project->workspace_id] ?? null, $changes, $branch);

            if ($changes !== null && $baseline !== null) {
                $this->recordSkippedPush($project, SkippedPushData::fromPush(
                    deliveryId: $deliveryId,
                    reason: PushSkipReason::NoChangesInRootDirectory,
                    commitSha: $commit,
                    commitMessage: CommitMessage::subject($message),
                    branch: $branch,
                    rootDirectory: MonorepoPathOwnership::normalize($project->root_directory),
                    changedPaths: $changes->paths,
                    afterDeploymentId: $baseline->id,
                ));

                continue;
            }

            $this->deploy($project, $branch, $commit, $message, $deliveryId);
        }
    }

    /**
     * Folder ownership per workspace, from every project of that workspace on
     * this repository and branch, auto-deploy or not: a folder belongs to its
     * project even while that project deploys by hand. Projects of other
     * workspaces never change what a push deploys here.
     *
     * @param  Collection<int, Project>  $candidates
     * @return array<string, MonorepoPathOwnership>
     */
    private function ownershipByWorkspace(Collection $candidates, int $repositoryId, string $branch): array
    {
        $workspaceIds = $candidates
            ->filter(fn (Project $project): bool => MonorepoPathOwnership::normalize($project->root_directory) !== '')
            ->pluck('workspace_id')
            ->filter()
            ->unique()
            ->values();

        if ($workspaceIds->isEmpty()) {
            return [];
        }

        return Project::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('github_repository_id', $repositoryId)
            ->where('branch', $branch)
            ->get(['workspace_id', 'root_directory'])
            ->groupBy('workspace_id')
            ->map(fn (Collection $group): MonorepoPathOwnership => new MonorepoPathOwnership(
                $group->pluck('root_directory')->all(),
            ))
            ->all();
    }

    /**
     * The deployment a skipped push follows, when the push concerns nothing in
     * the project; null when the project must deploy. It deploys when it has
     * no root directory, when a changed path is in its folder or shared, and
     * whenever its running version may lack an earlier change: no successful
     * deployment yet, the latest deployment failed, was cancelled, or is of
     * another branch, or a push was skipped for a limit since then.
     */
    private function baselineIfUnconcerned(Project $project, ?MonorepoPathOwnership $ownership, PushChangedPaths $changes, string $branch): ?Deployment
    {
        if ($ownership === null
            || MonorepoPathOwnership::normalize($project->root_directory) === ''
            || $ownership->relevantTo($project->root_directory, $changes->paths) !== []) {
            return null;
        }

        $latest = $project->latestDeployment()->first();

        if ($latest === null
            || $latest->branch !== $branch
            || in_array($latest->status, [DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)) {
            return null;
        }

        $hasShipped = $latest->status === DeploymentStatus::Succeeded
            || $project->deployments()->where('status', DeploymentStatus::Succeeded)->exists();

        $missedPush = AuditEvent::query()
            ->where('subject_type', Project::class)
            ->where('subject_id', $project->id)
            ->where('action', self::AUTO_DEPLOY_SKIPPED_ACTION)
            ->where('created_at', '>=', $latest->created_at)
            ->exists();

        return $hasShipped && ! $missedPush ? $latest : null;
    }

    /**
     * Only an audit event, inside the webhook's transaction: the delivery
     * record makes a redelivery a no-op, so it is written once per delivery
     * and project. No notification, no email.
     */
    private function recordSkippedPush(Project $project, SkippedPushData $skip): void
    {
        AuditEvent::create([
            'actor_type' => 'github_app',
            'action' => SkippedPushData::AUDIT_ACTION,
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'metadata' => $skip->toMetadata(),
        ]);
    }

    private function deploy(Project $project, string $branch, string $commit, ?string $message, string $deliveryId): void
    {
        $actor = $this->actorFor($project);

        if ($actor === null) {
            $this->skip($project, $actor, 'no_workspace_owner', $commit, 'Tidak ada pemilik atau anggota workspace yang bisa menjalankan deployment, jadi push terbaru tidak di-deploy.');

            return;
        }

        if ($actor->requiresLegalAcceptance()) {
            $this->skip($project, $actor, 'legal_acceptance_required', $commit, 'Syarat Layanan atau Kebijakan Penggunaan Wajar terbaru belum kamu setujui, jadi push terbaru tidak di-deploy. Setujui dulu di Console, lalu deploy manual.');

            return;
        }

        try {
            $this->createDeployment->handle(
                project: $project,
                user: $actor,
                data: new CreateDeploymentData(
                    branch: $branch,
                    idempotencyKey: "github-push:{$deliveryId}",
                    trigger: DeploymentTrigger::Webhook,
                    commit_sha: $commit,
                    commit_message: $message,
                ),
            );
        } catch (ActiveDeploymentLimitExceededException) {
            $this->skip($project, $actor, 'active_deployment_limit', $commit, 'Batas deployment yang berjalan sudah tercapai, jadi push terbaru tidak di-deploy. Tunggu deployment yang berjalan selesai, lalu deploy manual.');
        } catch (ActiveContainerLimitExceededException) {
            $this->skip($project, $actor, 'active_container_limit', $commit, 'Workspace ini sudah menjalankan proyek sebanyak batasnya, jadi push terbaru tidak di-deploy.');
        } catch (BuildRateLimitExceededException $e) {
            $this->skip($project, $actor, self::SKIP_BUILD_RATE_LIMIT, $commit, "Workspace ini sudah mencapai batas {$e->limit} build per jam, jadi push terbaru tidak di-deploy. Deploy manual atau push ulang {$e->retryHint()}.");
        } catch (RepositoryAccessRevokedException) {
            $this->skip($project, $actor, 'repository_access_revoked', $commit, 'Pembuat proyek tidak lagi punya akses ke repository ini di GitHub atau sudah keluar dari workspace, jadi push terbaru tidak di-deploy.');
        } catch (ProjectSuspendedException) {
            $this->skip($project, $actor, 'project_suspended', $commit, 'Proyek ini sedang ditangguhkan, jadi push terbaru tidak di-deploy.');
        } catch (DatabaseEndpointUnavailableException $e) {
            $this->skip($project, $actor, self::SKIP_DATABASE_ENDPOINT_UNAVAILABLE, $commit, "Server database untuk {$e->database->name} belum melaporkan alamatnya, jadi push terbaru tidak di-deploy. Deploy manual beberapa saat lagi.");
        } catch (DatabaseNotReadyException $e) {
            $this->skip($project, $actor, self::SKIP_DATABASE_NOT_READY, $commit, $e->status === DatabaseStatus::Provisioning
                ? 'Database proyek ini masih dibuat, jadi push terbaru tidak di-deploy. Deploy manual setelah database-nya siap.'
                : 'Database proyek ini gagal dibuat, jadi push terbaru tidak di-deploy. Lepas database itu dari proyek, buat yang baru, lalu deploy manual.');
        } catch (ProjectReleaseCommandUnsupportedException) {
            $this->skip($project, $actor, self::SKIP_RELEASE_COMMAND_UNSUPPORTED, $commit, 'Node yang menjalankan proyek ini belum mendukung perintah rilis, jadi push terbaru tidak di-deploy. Kosongkan perintah rilis di pengaturan proyek, atau tunggu operator memperbarui agent, lalu deploy manual.');
        }
    }

    /** $actor is who the push would have deployed as; they hear about it by email with the workspace owners. */
    private function skip(Project $project, ?User $actor, string $reason, string $commit, string $message): void
    {
        AuditEvent::create([
            'actor_type' => 'github_app',
            'action' => self::AUTO_DEPLOY_SKIPPED_ACTION,
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'metadata' => ['reason' => $reason, 'commit_sha' => $commit],
        ]);

        $this->notifier->sendToProject(
            $project,
            NotificationCategory::ProjectIssue,
            'Auto-deploy dilewati',
            "{$project->name}: {$message}",
        );

        $this->notifier->emailProject($project, new ProjectAlertMailData(
            alert: ProjectAlert::AutoDeploySkipped,
            projectId: $project->id,
            projectName: $project->name,
            summary: $message,
            previousVersionServing: $project->active_deployment_id !== null,
        ), $actor);
    }

    /**
     * Who a push deploys as. In a personal workspace that is the owner, as
     * before workspaces. On a team it is the creator while they can still
     * deploy there, and otherwise the longest-standing owner, so a creator who
     * left does not stop auto-deploy. Their legal acceptance is what counts.
     */
    private function actorFor(Project $project): ?User
    {
        $workspace = $project->workspace;

        if ($workspace === null) {
            return null;
        }

        if ($workspace->isPersonal()) {
            return $workspace->personalUser;
        }

        $creatorRole = $project->user === null ? null : $workspace->roleOf($project->user);

        if ($creatorRole?->managesProjects() === true) {
            return $project->user;
        }

        $ownerId = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('role', WorkspaceRole::Owner->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('user_id');

        return $ownerId === null ? null : User::query()->whereKey($ownerId)->first();
    }

    /** @param array<string, mixed> $payload */
    private function branch(array $payload): ?string
    {
        $ref = $payload['ref'] ?? null;

        return is_string($ref) && str_starts_with($ref, 'refs/heads/')
            ? substr($ref, strlen('refs/heads/'))
            : null;
    }
}
