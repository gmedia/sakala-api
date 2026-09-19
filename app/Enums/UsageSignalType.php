<?php

declare(strict_types=1);

namespace App\Enums;

enum UsageSignalType: string
{
    case DeploymentAttempt = 'deployment_attempt';
    case SuccessfulDeployment = 'successful_deployment';
    case ActiveProjects = 'active_projects';
    case RejectedLimits = 'rejected_limits';
    case AgentFailure = 'agent_failure';
    case RepeatedBuildFailure = 'repeated_build_failure';
    case ManualIntervention = 'manual_intervention';
}
