<?php

declare(strict_types=1);

namespace App\Enums;

enum GithubOAuthFailure: string
{
    case AccessDenied = 'github_access_denied';
    case EmailConflict = 'github_email_conflict';
    case EmailUnavailable = 'github_email_unavailable';
    case InvalidState = 'github_invalid_state';
    case ProviderFailure = 'github_provider_failure';
}
