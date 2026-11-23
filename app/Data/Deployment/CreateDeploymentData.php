<?php

declare(strict_types=1);

namespace App\Data\Deployment;

use App\Data\Runtime\RuntimeResourceLimitsData;
use App\Enums\DeploymentTrigger;
use App\Models\Deployment;

final readonly class CreateDeploymentData
{
    public function __construct(
        public string $branch,
        public ?string $idempotencyKey = null,
        public ?RuntimeResourceLimitsData $requested_resources = null,
        public DeploymentTrigger $trigger = DeploymentTrigger::Manual,
        public ?string $commit_sha = null,
        public ?string $commit_message = null,
        // True always builds; false lets PlanDeploymentBuildAction reuse the live image.
        public bool $forceBuild = false,
        // GitHub name of the pusher, for a webhook deployment.
        public ?string $triggeredByName = null,
        // A rollback: go back to this deployment (its commit, recorded settings, and image).
        public ?Deployment $rollbackTarget = null,
        // Run with what this deployment recorded instead of the project's settings (DeploymentSettingsData::recordedBy()).
        public ?Deployment $settingsSource = null,
    ) {}

    /**
     * A rollback to `$target`: its branch and commit, its recorded settings,
     * and its image when the node still has it.
     */
    public static function rollback(Deployment $target, ?string $idempotencyKey): self
    {
        return new self(
            branch: $target->branch,
            idempotencyKey: $idempotencyKey,
            trigger: DeploymentTrigger::Rollback,
            commit_sha: $target->commit_sha,
            commit_message: $target->commit_message,
            rollbackTarget: $target,
            settingsSource: $target,
        );
    }
}
