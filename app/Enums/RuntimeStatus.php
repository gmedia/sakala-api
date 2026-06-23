<?php

declare(strict_types=1);

namespace App\Enums;

enum RuntimeStatus: string
{
    case NotDeployed = 'not_deployed';
    case Deploying = 'deploying';
    case Running = 'running';
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Crashed = 'crashed';
}
