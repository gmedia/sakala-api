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
            self::ResumeNode => false,
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
            self::ResumeNode => true,
            default => false,
        };
    }

    /**
     * Commands whose stored payload is sent to the agent. Lifecycle commands
     * carry identity only; the API must not leak Docker names, shell
     * commands, or credentials for them.
     */
    public function carriesPayload(): bool
    {
        return match ($this) {
            self::InspectProject,
            self::DeployProject,
            self::ReconcileWorkload,
            self::CleanupRuntime => true,
            default => false,
        };
    }
}
