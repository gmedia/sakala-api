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
}
