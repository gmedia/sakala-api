<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Values must match `sakala-agent-protocol::DesiredWorkloadState` (protocol revision 4).
 */
enum DesiredWorkloadState: string
{
    case Running = 'running';
    case Stopped = 'stopped';
    case Missing = 'missing';
}
