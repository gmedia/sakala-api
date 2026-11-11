<?php

declare(strict_types=1);

namespace App\Data\Workspace;

use App\Models\WorkspaceInvitation;

/**
 * An open invitation as its holder sees it, and the error code accepting it
 * would answer right now, or null when it can be accepted.
 */
final readonly class WorkspaceInvitationDetailsData
{
    public function __construct(
        public WorkspaceInvitation $invitation,
        public ?string $accept_error_code,
    ) {}
}
