<?php

declare(strict_types=1);

namespace App\Enums;

enum DeploymentStatus: string
{
    case Queued = 'queued';
    case Cloning = 'cloning';
    case Analyzing = 'analyzing';
    case Building = 'building';
    case Deploying = 'deploying';
    case Routing = 'routing';
    case HealthChecking = 'health_checking';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded,
            self::Failed,
            self::Cancelled => true,

            default => false,
        };
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Position in the forward lifecycle. Terminal states share the highest
     * rank so they are never "behind" an active state.
     */
    public function order(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Cloning => 1,
            self::Analyzing => 2,
            self::Building => 3,
            self::Deploying => 4,
            self::Routing => 5,
            self::HealthChecking => 6,
            self::Succeeded,
            self::Failed,
            self::Cancelled => 7,
        };
    }

    /**
     * Map a DeployProject event reported by the agent to the deployment
     * phase it announces. `succeeded` is never derived from an event; only
     * the command completion is authoritative for that. Events that carry no
     * phase (resources resolved, command claimed, ...) return null.
     */
    public static function fromAgentEventType(string $type): ?self
    {
        return match ($type) {
            'deployment.checkout.started' => self::Cloning,
            'deployment.build.started' => self::Building,
            'deployment.container.started' => self::Deploying,
            'deployment.runtime.ready' => self::Routing,
            default => null,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function activeCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status) => $status->isActive()
        ));
    }
}
