<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationCategory: string
{
    case DeploymentSuccess = 'deployment_success';
    case DeploymentFailure = 'deployment_failure';
    case ProjectIssue = 'project_issue';
    case AccountSecurity = 'account_security';
}
