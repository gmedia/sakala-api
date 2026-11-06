<?php

declare(strict_types=1);

namespace App\Enums;

enum GithubInstallationFailure: string
{
    case NotConfigured = 'github_app_not_configured';
    case InvalidState = 'github_invalid_state';
    case InvalidInstallation = 'github_invalid_installation';
    case AccessDenied = 'github_access_denied';
    case ProviderFailure = 'github_provider_failure';
}
