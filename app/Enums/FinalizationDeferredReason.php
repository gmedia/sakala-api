<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why the agent could not finish post-commit finalization of a deployment.
 * Values must match `sakala-agent-protocol::FinalizationDeferredReason`.
 */
enum FinalizationDeferredReason: string
{
    case GraceElapsed = 'grace_elapsed';
    case RuntimeError = 'runtime_error';
}
