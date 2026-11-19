<?php

declare(strict_types=1);

namespace App\Enums;

enum DeploymentTrigger: string
{
    case Manual = 'manual';
    case Redeploy = 'redeploy';
    case Webhook = 'webhook';
    case System = 'system';

    /**
     * Queued by the API after a managed database's new password took effect,
     * for a project attached to it, when the rotation asked for it.
     */
    case DatabasePasswordRotated = 'database_password_rotated';
}
