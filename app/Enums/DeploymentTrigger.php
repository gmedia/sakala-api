<?php

declare(strict_types=1);

namespace App\Enums;

enum DeploymentTrigger: string
{
    case Manual = 'manual';
    case Redeploy = 'redeploy';
    case Webhook = 'webhook';
    case System = 'system';

    /**
     * Queued by the API after a managed database's new password took effect,
     * for a project attached to it, when the rotation asked for it.
     */
    case DatabasePasswordRotated = 'database_password_rotated';

    /**
     * Queued by the API once a database created for a project with
     * `deploy_when_ready` is ready: the project's first deployment with it.
     */
    case DatabaseReady = 'database_ready';

    /**
     * A member went back to an earlier successful deployment: its commit and
     * recorded settings, with the project's current variables
     * (RollbackDeploymentAction).
     */
    case Rollback = 'rollback';

    /**
     * Who asked for a deployment of this trigger, as DeploymentResource's
     * `triggered_by.kind` names it: a member, a GitHub push, or Sakala itself.
     */
    public function actorKind(): DeploymentActorKind
    {
        return match ($this) {
            self::Manual, self::Redeploy, self::Rollback => DeploymentActorKind::User,
            self::Webhook => DeploymentActorKind::GithubPush,
            self::System, self::DatabasePasswordRotated, self::DatabaseReady => DeploymentActorKind::System,
        };
    }
}
