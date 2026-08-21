<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Suspended = 'suspended';
}
