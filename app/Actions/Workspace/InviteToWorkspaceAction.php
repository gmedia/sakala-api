<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\NotificationCategory;
use App\Enums\WorkspaceInvitationStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\Workspace\AlreadyWorkspaceMemberException;
use App\Exceptions\Workspace\WorkspaceIsPersonalException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Notifications\Workspace\WorkspaceInvitationNotification;
use App\Services\Notification\UserNotifier;
use App\Support\Workspace\WorkspaceAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Invites an address with a role. Inviting the same address again replaces the
 * pending invitation: a new token, a new expiry, the new role, and a new email.
 * The earlier one is closed as revoked, so its link answers INVITATION_REVOKED.
 * An account already using the address, with the email verified, also sees an
 * in-app notice, unless it switched the `workspace` category off; it never
 * contains the token. The email is always sent: it is the invitation.
 */
final class InviteToWorkspaceAction
{
    public function __construct(
        private readonly UserNotifier $notifier,
    ) {}

    public function handle(User $actor, Workspace $workspace, string $email, WorkspaceRole $role): WorkspaceInvitation
    {
        if ($workspace->isPersonal()) {
            throw new WorkspaceIsPersonalException;
        }

        $email = Str::lower(trim($email));
        $token = Str::random(64);
        $ttlHours = (int) config('sakala.workspaces.invitation_ttl_hours', 168);

        return DB::transaction(function () use ($actor, $workspace, $email, $role, $token, $ttlHours): WorkspaceInvitation {
            WorkspaceOwnership::lock($workspace);

            $alreadyMember = WorkspaceMember::query()
                ->where('workspace_id', $workspace->id)
                ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))
                ->exists();

            if ($alreadyMember) {
                throw new AlreadyWorkspaceMemberException;
            }

            WorkspaceInvitation::query()
                ->pending()
                ->where('workspace_id', $workspace->id)
                ->where('email', $email)
                ->get()
                ->each(function (WorkspaceInvitation $replaced) use ($actor, $workspace): void {
                    $replaced->update(['status' => WorkspaceInvitationStatus::Revoked, 'closed_at' => now()]);

                    WorkspaceAudit::record($actor, 'workspace.invitation_revoked', $workspace, [
                        'invitation_id' => $replaced->id,
                        'role' => $replaced->role->value,
                        'reason' => 'replaced',
                    ]);
                });

            $invitation = WorkspaceInvitation::query()->create([
                'workspace_id' => $workspace->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => WorkspaceInvitation::hashToken($token),
                'invited_by' => $actor->id,
                'expires_at' => now()->addHours($ttlHours),
            ]);

            WorkspaceAudit::record($actor, 'workspace.invitation_created', $workspace, [
                'invitation_id' => $invitation->id,
                'role' => $role->value,
            ]);

            $mail = new WorkspaceInvitationNotification(
                invitationId: $invitation->id,
                token: $token,
                workspaceName: $workspace->name,
                inviterName: $actor->name,
                role: $role->value,
                ttlHours: $ttlHours,
            );

            DB::afterCommit(static function () use ($email, $mail): void {
                try {
                    Notification::route('mail', $email)->notify($mail);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

            $invitee = User::query()->whereRaw('LOWER(email) = ?', [$email])->whereNotNull('email_verified_at')->first();

            $this->notifier->send(
                $invitee,
                NotificationCategory::Workspace,
                'Undangan workspace tim',
                "{$actor->name} mengundangmu bergabung ke {$workspace->name}. Buka undangannya untuk menerima atau menolak.",
                invitationId: $invitation->id,
            );

            return $invitation->load(['workspace', 'inviter']);
        });
    }
}
