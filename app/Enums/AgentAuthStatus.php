<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentAuthStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Suspended = 'suspended';
}
