<?php

declare(strict_types=1);

namespace App\Data\Deployment;

use App\Enums\DeploymentBuildMode;

/**
 * What PlanDeploymentBuildAction decided for a new deployment: build, or reuse
 * the image `imageSourceDeploymentId` built. Only the named constructors make
 * one, so a reuse always has a source and a build never does.
 */
final readonly class DeploymentBuildPlanData
{
    private function __construct(
        public DeploymentBuildMode $mode,
        public ?string $imageSourceDeploymentId,
    ) {}

    public static function build(): self
    {
        return new self(DeploymentBuildMode::Built, null);
    }

    public static function reuse(string $imageSourceDeploymentId): self
    {
        return new self(DeploymentBuildMode::Reused, $imageSourceDeploymentId);
    }

    public function reuses(): bool
    {
        return $this->mode === DeploymentBuildMode::Reused;
    }
}
