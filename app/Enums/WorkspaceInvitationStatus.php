<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an invitation stands. Only a pending one can be answered or revoked;
 * the others stay until they are pruned, so the link can say why it no longer
 * works. Expiry is not a status: it follows from `expires_at`.
 */
enum WorkspaceInvitationStatus: string
{
    case Pending = 'pending';

    case Accepted = 'accepted';

    case Declined = 'declined';

    /** Revoked by an owner, replaced by a newer invitation to the same address, or taken along by an inviter who lost the owner role. */
    case Revoked = 'revoked';
}
