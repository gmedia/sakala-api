<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Actions\Deployment\CreateDeploymentAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Enums\DeploymentTrigger;
use App\Enums\GithubInstallationStatus;
use App\Enums\NotificationCategory;
use App\Enums\WorkspaceRole;
use App\Exceptions\Runtime\ActiveContainerLimitExceededException;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\RepositoryAccessRevokedException;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\Notification\UserNotifier;

/**
 * Turns a verified GitHub push into deployments for the projects that opted
 * in. The delivery ID is the idempotency key, on top of the delivery record the
 * webhook controller already holds, so a redelivery creates nothing new. A push
 * never bypasses a limit: an attempt over the limit, or on a suspended project,
 * is skipped, audited, and reported to the workspace's owners and members.
 */
final class CreatePushDeploymentsAction
{
    private const NO_COMMIT = '0000000000000000000000000000000000000000';

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
            ->with(['user', 'workspace'])
            ->where('auto_deploy', true)
            ->where('github_repository_id', $repository['id'])
            ->where('branch', $branch)
            ->whereHas('githubInstallation', function ($query) use ($installation): void {
                $query->where('github_installation_id', $installation['id'])
                    ->where('status', GithubInstallationStatus::Active);
            })
            ->get();

        foreach ($projects as $project) {
            $this->deploy($project, $branch, $commit, $message, $deliveryId);
        }
    }

    private function deploy(Project $project, string $branch, string $commit, ?string $message, string $deliveryId): void
    {
        $actor = $this->actorFor($project);

        if ($actor === null) {
            $this->skip($project, 'no_workspace_owner', $commit, 'Tidak ada pemilik atau anggota workspace yang bisa menjalankan deployment, jadi push terbaru tidak di-deploy.');

            return;
        }

        if ($actor->requiresLegalAcceptance()) {
            $this->skip($project, 'legal_acceptance_required', $commit, 'Syarat Layanan atau Kebijakan Penggunaan Wajar terbaru belum kamu setujui, jadi push terbaru tidak di-deploy. Setujui dulu di Console, lalu deploy manual.');

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
            $this->skip($project, 'active_deployment_limit', $commit, 'Batas deployment yang berjalan sudah tercapai, jadi push terbaru tidak di-deploy. Tunggu deployment yang berjalan selesai, lalu deploy manual.');
        } catch (ActiveContainerLimitExceededException) {
            $this->skip($project, 'active_container_limit', $commit, 'Workspace ini sudah menjalankan proyek sebanyak batasnya, jadi push terbaru tidak di-deploy.');
        } catch (RepositoryAccessRevokedException) {
            $this->skip($project, 'repository_access_revoked', $commit, 'Pembuat proyek tidak lagi punya akses ke repository ini di GitHub atau sudah keluar dari workspace, jadi push terbaru tidak di-deploy.');
        } catch (ProjectSuspendedException) {
            $this->skip($project, 'project_suspended', $commit, 'Proyek ini sedang ditangguhkan, jadi push terbaru tidak di-deploy.');
        }
    }

    private function skip(Project $project, string $reason, string $commit, string $message): void
    {
        AuditEvent::create([
            'actor_type' => 'github_app',
            'action' => 'project.auto_deploy_skipped',
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
