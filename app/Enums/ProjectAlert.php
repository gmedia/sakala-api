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

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::DeploymentFailed => NotificationCategory::DeploymentFailure,
            self::DeploymentSucceeded => NotificationCategory::DeploymentSuccess,
            self::AppCrashed,
            self::AutoDeploySkipped,
            self::WorkerCrashed,
            self::ScheduleFailing,
            self::SchedulePaused => NotificationCategory::ProjectIssue,
        };
    }
}
