<?php

declare(strict_types=1);

namespace App\Enums;

enum GithubRepositorySource: string
{
    case PublicUrl = 'public_url';
    case GithubInstallation = 'github_installation';
}
