<?php

declare(strict_types=1);

namespace App\Data\Notification;

use App\Enums\DeploymentStatus;
use App\Enums\ProjectAlert;

/**
 * What an alert email says. Only safe, fixed text reaches it: the failure
 * summary and hint come from the classifier, never from the build log.
 */
final readonly class ProjectAlertMailData
{
    public function __construct(
        public ProjectAlert $alert,
        public string $projectId,
        public string $projectName,
        public ?string $deploymentId = null,
        public ?int $deploymentSequence = null,
        public ?DeploymentStatus $failureStage = null,
        /** The failure summary, or why a push was skipped. */
        public ?string $summary = null,
        public ?string $recoveryHint = null,
        /** Whether an earlier version still serves visitors. */
        public bool $previousVersionServing = false,
    ) {}
}
