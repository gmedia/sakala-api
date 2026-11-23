<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who asked for a deployment, as `triggered_by.kind` of DeploymentResource
 * (DeploymentTrigger::actorKind()).
 */
enum DeploymentActorKind: string
{
    /** A member, by hand: deploy, redeploy, or rollback. */
    case User = 'user';

    /** A push to the project's branch on GitHub (auto-deploy). */
    case GithubPush = 'github_push';

    /** Sakala itself, for example after a database password rotation. */
    case System = 'system';
}
