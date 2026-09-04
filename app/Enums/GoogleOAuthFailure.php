<?php

declare(strict_types=1);

namespace App\Enums;

enum GoogleOAuthFailure: string
{
    case AccessDenied = 'google_access_denied';
    case EmailConflict = 'google_email_conflict';
    case EmailUnavailable = 'google_email_unavailable';
    case InvalidState = 'google_invalid_state';
    case ProviderFailure = 'google_provider_failure';
}
