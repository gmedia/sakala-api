<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why auto-deploy of a project is paused (`auto_deploy_pause.reason` of
 * ProjectResource). Not stored: a rollback is the only thing that pauses it.
 */
enum AutoDeployPauseReason: string
{
    /** A rollback went back to an earlier version; a push would undo it. */
    case Rollback = 'rollback';
}
