<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Command types shared with sakala-agent protocol revision 4. Case order and
 * values must match `sakala-agent-protocol::CommandType` exactly.
 */
enum AgentCommandType: string
{
    case InspectProject = 'InspectProject';
    case DeployProject = 'DeployProject';
    case RestartProject = 'RestartProject';
    case StopProject = 'StopProject';
    case SleepProject = 'SleepProject';
    case WakeProject = 'WakeProject';
    case HealthCheck = 'HealthCheck';
    case RefreshRoute = 'RefreshRoute';
    case ReconcileWorkload = 'ReconcileWorkload';
    case CleanupRuntime = 'CleanupRuntime';
    case DrainNode = 'DrainNode';
    case ResumeNode = 'ResumeNode';
    case CreateDatabase = 'CreateDatabase';
    case DropDatabase = 'DropDatabase';
    case RotateDatabasePassword = 'RotateDatabasePassword';
    case LockDatabase = 'LockDatabase';
    case UnlockDatabase = 'UnlockDatabase';
    case InstallDatabaseExtension = 'InstallDatabaseExtension';

    /**
     * Capability of a node that hosts the shared PostgreSQL instance of its
     * region and runs the database commands (ADR-020).
     */
    public const MANAGED_POSTGRES_CAPABILITY = 'managed-postgres';

    /**
     * Return the set of node capabilities required to handle this command type.
     * A node is eligible only if its capabilities intersect this set non-empty.
     * An empty set means any authenticated node may execute the command.
     */
    /** @return list<string> */
    public function requiredCapabilities(): array
    {
        return match ($this) {
            self::InspectProject => ['project-inspection'],
            self::DeployProject => ['dockerfile-build', 'railpack-build'],
            self::RestartProject,
            self::StopProject,
            self::SleepProject,
            self::WakeProject,
            self::HealthCheck,
            self::ReconcileWorkload,
            self::CleanupRuntime => ['docker-runtime'],
            self::RefreshRoute => ['caddy-file-routing'],
            self::CreateDatabase,
            self::DropDatabase,
            self::RotateDatabasePassword,
            self::LockDatabase,
            self::UnlockDatabase,
            self::InstallDatabaseExtension => [self::MANAGED_POSTGRES_CAPABILITY],
            self::DrainNode,
            self::ResumeNode => [],
        };
    }

    /**
     * Commands that could start or expose a workload again while the project
     * is suspended. ReconcileWorkload is blocked because its payload may
     * request `desired_state = running` or `restore_route`; a payload-aware
     * policy can relax this later. Stop, sleep, health checks, and node-level
     * commands never bring a workload back.
     */
    public function isBlockedForSuspendedProject(): bool
    {
        return match ($this) {
            self::InspectProject,
            self::DeployProject,
            self::RestartProject,
            self::WakeProject,
            self::RefreshRoute,
            self::ReconcileWorkload => true,

            self::StopProject,
            self::SleepProject,
            self::HealthCheck,
            self::CleanupRuntime,
            self::DrainNode,
            self::ResumeNode,
            self::CreateDatabase,
            self::DropDatabase,
            self::RotateDatabasePassword,
            self::LockDatabase,
            self::UnlockDatabase,
            self::InstallDatabaseExtension => false,
        };
    }

    /**
     * Commands that target the node itself and never carry project or
     * deployment identity.
     */
    public function isNodeLevel(): bool
    {
        return match ($this) {
            self::CleanupRuntime,
            self::DrainNode,
            self::ResumeNode => true,
            default => false,
        };
    }

    /**
     * Commands for one managed database (`agent_commands.database_id`). They
     * carry no project or deployment identity and always go to the node that
     * hosts the database, chosen when the database was created.
     */
    public function isDatabase(): bool
    {
        return match ($this) {
            self::CreateDatabase,
            self::DropDatabase,
            self::RotateDatabasePassword,
            self::LockDatabase,
            self::UnlockDatabase,
            self::InstallDatabaseExtension => true,
            default => false,
        };
    }

    /**
     * Database commands whose payload carries a password, encrypted at rest
     * like DeployProject's environment and decrypted only for the pinned node.
     */
    public function carriesDatabasePassword(): bool
    {
        return $this === self::CreateDatabase || $this === self::RotateDatabasePassword;
    }

    /**
     * Commands that start, stop, or put to sleep a project's kept workload.
     * While one is open the project is already changing.
     */
    public function isProjectLifecycle(): bool
    {
        return match ($this) {
            self::RestartProject,
            self::StopProject,
            self::SleepProject,
            self::WakeProject => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function projectLifecycleCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => $type->isProjectLifecycle(),
        ));
    }

    /**
     * Commands the agent still processes while its lifecycle is not active.
     */
    public function isNodeLifecycle(): bool
    {
        return $this === self::DrainNode || $this === self::ResumeNode;
    }

    /**
     * Commands whose target node is decided by the control plane at creation.
     * They are only offered to that node; an unassigned command is invisible.
     */
    public function isPinnedAtCreation(): bool
    {
        return match ($this) {
            self::InspectProject,
            self::DeployProject,
            self::CleanupRuntime,
            self::DrainNode,
            self::ResumeNode,
            self::CreateDatabase,
            self::DropDatabase,
            self::RotateDatabasePassword,
            self::LockDatabase,
            self::UnlockDatabase,
            self::InstallDatabaseExtension => true,
            default => false,
        };
    }

    /**
     * Commands whose stored payload is sent to the agent. Other lifecycle
     * commands carry identity only; the API must not leak Docker names, shell
     * commands, or credentials for them. StopProject carries at most the
     * optional `remove_images` flag that a project delete sets, and
     * SleepProject at most the optional `page` a suspension sets (SleepPage).
     * Database commands carry the database and role names, and a password
     * where one is set.
     */
    public function carriesPayload(): bool
    {
        return match ($this) {
            self::InspectProject,
            self::DeployProject,
            self::StopProject,
            self::SleepProject,
            self::ReconcileWorkload,
            self::CleanupRuntime,
            self::CreateDatabase,
            self::DropDatabase,
            self::RotateDatabasePassword,
            self::LockDatabase,
            self::UnlockDatabase,
            self::InstallDatabaseExtension => true,
            default => false,
        };
    }
}
