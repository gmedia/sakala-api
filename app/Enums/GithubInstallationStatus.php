<?php

declare(strict_types=1);

namespace App\Enums;

enum GithubInstallationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Removed = 'removed';
}
