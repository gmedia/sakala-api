<?php

declare(strict_types=1);

namespace App\Enums;

enum DeploymentTrigger: string
{
    case Manual = 'manual';
    case Redeploy = 'redeploy';
    case Webhook = 'webhook';
    case System = 'system';
}
