<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectControlAction: string
{
    case Stop = 'stop';
    case Suspend = 'suspend';
    case Reconcile = 'reconcile';
    case Unsuspend = 'unsuspend';

    /** A member restarted the live containers without a build (RestartProjectAction). */
    case Restart = 'restart';
}
