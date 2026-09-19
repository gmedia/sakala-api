<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentNodeControlAction: string
{
    case Drain = 'drain';
    case Resume = 'resume';
}
