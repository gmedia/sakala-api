<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a schedule's last finished run failed. Members are notified when
 * it changes, never on every failure.
 */
enum ScheduleHealth: string
{
    case Healthy = 'healthy';
    case Failing = 'failing';
}
