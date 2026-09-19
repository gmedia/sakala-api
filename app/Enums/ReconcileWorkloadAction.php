<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Values must match `sakala-agent-protocol::ReconcileWorkloadAction` (protocol revision 4).
 */
enum ReconcileWorkloadAction: string
{
    case RestartLogFollower = 'restart_log_follower';
    case CleanupFailedCandidate = 'cleanup_failed_candidate';
    case RestoreRoute = 'restore_route';
}
