<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A project event that can be emailed. Repeats of one alert on one project are
 * coalesced, so each kind keeps its own window: a skipped push must never hold
 * back the email about a crash.
 */
enum ProjectAlert: string
{
    case DeploymentFailed = 'deployment_failed';
    case DeploymentSucceeded = 'deployment_succeeded';
    case AppCrashed = 'app_crashed';
    case AutoDeploySkipped = 'auto_deploy_skipped';
    /** A worker process of the serving deployment crashes (ADR-022); `summary` is its name. */
    case WorkerCrashed = 'worker_crashed';
    /** A schedule's runs started failing (ADR-022); `summary` is its name. */
    case ScheduleFailing = 'schedule_failing';
    /** A schedule was paused after too many failures in a row; `summary` is its name. */
    case SchedulePaused = 'schedule_paused';
    /** A custom domain kept failing its checks for 24 hours (ADR-024); `summary` is the hostname. */
    case CustomDomainFailing = 'custom_domain_failing';
    /** A custom domain was disabled after 72 hours of failing checks; `summary` is the hostname. */
    case CustomDomainDisabled = 'custom_domain_disabled';
    /** A disabled custom domain passed again and serves the project; `summary` is the hostname. */
    case CustomDomainReactivated = 'custom_domain_reactivated';
    /** Another project verified the name of a disabled custom domain; `summary` is the hostname. */
    case CustomDomainReleased = 'custom_domain_released';

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::DeploymentFailed => NotificationCategory::DeploymentFailure,
            self::DeploymentSucceeded => NotificationCategory::DeploymentSuccess,
            self::AppCrashed,
            self::AutoDeploySkipped,
            self::WorkerCrashed,
            self::ScheduleFailing,
            self::SchedulePaused,
            self::CustomDomainFailing,
            self::CustomDomainDisabled,
            self::CustomDomainReactivated,
            self::CustomDomainReleased => NotificationCategory::ProjectIssue,
        };
    }
}
