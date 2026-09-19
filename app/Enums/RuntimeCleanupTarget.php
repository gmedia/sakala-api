<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Values must match `sakala-agent-protocol::RuntimeCleanupTarget` (protocol revision 4).
 */
enum RuntimeCleanupTarget: string
{
    case StaleWorkspaces = 'stale_workspaces';
    case StaleImages = 'stale_images';
    case StaleRoutes = 'stale_routes';
}
