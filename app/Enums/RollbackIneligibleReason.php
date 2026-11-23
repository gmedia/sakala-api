<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a deployment cannot be rolled back to (`reason` of
 * `409 DEPLOYMENT_NOT_ROLLBACK_TARGET`). DeploymentRollbackEligibility decides.
 */
enum RollbackIneligibleReason: string
{
    /** It did not succeed: queued, running, failed, or cancelled. */
    case NotSucceeded = 'not_succeeded';

    /** It is the deployment the project runs now. */
    case AlreadyActive = 'already_active';

    /** It has no known commit, so there is nothing to go back to. */
    case NoCommit = 'no_commit';

    /** It ran another branch than the project's current one. */
    case BranchChanged = 'branch_changed';

    /**
     * Its recorded settings (the DeployProject command) are gone, so it
     * cannot be run again as it ran. Only rows that never had one.
     */
    case SettingsUnavailable = 'settings_unavailable';
}
