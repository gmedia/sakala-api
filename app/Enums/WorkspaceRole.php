<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkspaceRole: string
{
    /** Manages members, invitations, and the workspace itself, and everything a member can. */
    case Owner = 'owner';

    /** Creates, deploys, and manages the workspace's projects, environment variables included. */
    case Member = 'member';

    /** Reads projects, deployments, and logs; never a secret value, never a change. */
    case Viewer = 'viewer';

    public function managesProjects(): bool
    {
        return $this !== self::Viewer;
    }

    public function managesWorkspace(): bool
    {
        return $this === self::Owner;
    }

    /** @return list<string> */
    public static function projectManagerValues(): array
    {
        return [self::Owner->value, self::Member->value];
    }
}
