<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Control-plane lifecycle intent for a runtime node. Values must match
 * `sakala-agent-protocol::DesiredNodeLifecycleState` (protocol revision 4).
 */
enum AgentNodeDesiredState: string
{
    case Active = 'active';
    case Draining = 'draining';
    case Drained = 'drained';
    case Maintenance = 'maintenance';
}
