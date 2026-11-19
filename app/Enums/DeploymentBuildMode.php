<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a deployment got its image. `Reused` is decided when the deployment is
 * created (PlanDeploymentBuildAction) and stays unless the agent reports that
 * it had to build after all (`deployment.image.reuse_skipped`), which makes it
 * `Built`. Every deployment from before image reuse is `Built`.
 */
enum DeploymentBuildMode: string
{
    /** The agent builds (or built) an image from the commit. */
    case Built = 'built';

    /** The agent starts the image an earlier deployment of the project built. */
    case Reused = 'reused';
}
