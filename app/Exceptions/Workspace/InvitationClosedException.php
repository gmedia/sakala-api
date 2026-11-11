<?php

declare(strict_types=1);

namespace App\Exceptions\Workspace;

use App\Enums\WorkspaceInvitationStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * The invitation was already answered or revoked. Only someone who proved the
 * invitation is theirs (the token, or the invited verified address) ever sees
 * this; everyone else gets the same 404 as for an ID that does not exist.
 */
final class InvitationClosedException extends WorkspaceRuleException
{
    public function __construct(
        public readonly WorkspaceInvitationStatus $invitationStatus,
    ) {
        parent::__construct(match ($invitationStatus) {
            WorkspaceInvitationStatus::Accepted => 'Undangan ini sudah diterima dan tidak bisa dipakai lagi.',
            WorkspaceInvitationStatus::Declined => 'Undangan ini sudah ditolak. Minta pemilik workspace mengirim undangan baru kalau ingin bergabung.',
            default => 'Undangan ini sudah dibatalkan. Minta pemilik workspace mengirim undangan baru kalau masih perlu.',
        });
    }

    public function errorCode(): string
    {
        return match ($this->invitationStatus) {
            WorkspaceInvitationStatus::Accepted => 'INVITATION_ALREADY_ACCEPTED',
            WorkspaceInvitationStatus::Declined => 'INVITATION_DECLINED',
            default => 'INVITATION_REVOKED',
        };
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
