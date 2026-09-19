<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Data\Agent\ProjectInspectionResultData;
use App\Enums\AgentCommandType;
use App\Enums\ProjectInspectionStatus;
use App\Models\AgentCommand;
use App\Models\Project;

/**
 * Writes the outcome of an InspectProject command onto its project.
 *
 * Only the newest inspection command for a project may write its preview
 * state. Every path that records an outcome (completion, failure, expiry)
 * goes through here so they all take the project row lock *before* the
 * stale check; RequestProjectInspectionAction locks the same row when it
 * creates a newer command, which makes the ordering deterministic in either
 * direction. Must be called inside a transaction.
 */
final class ProjectInspectionOutcomeService
{
    /**
     * @return Project|null the project when the outcome was written, null when
     *                      the command had already been superseded
     */
    public function recordSuccess(AgentCommand $command, ProjectInspectionResultData $result): ?Project
    {
        $project = $this->lockUnlessSuperseded($command);

        $project?->update([
            'inspection' => $result->toArray(),
            'inspection_status' => ProjectInspectionStatus::Succeeded,
            'inspection_error_code' => null,
            'inspected_at' => now(),
        ]);

        return $project;
    }

    /**
     * @return Project|null the project when the outcome was written, null when
     *                      the command had already been superseded
     */
    public function recordFailure(AgentCommand $command, string $errorCode): ?Project
    {
        $project = $this->lockUnlessSuperseded($command);

        $project?->update([
            'inspection_status' => ProjectInspectionStatus::Failed,
            'inspection_error_code' => $errorCode,
        ]);

        return $project;
    }

    private function lockUnlessSuperseded(AgentCommand $command): ?Project
    {
        if ($command->project_id === null) {
            return null;
        }

        $project = Project::query()
            ->whereKey($command->project_id)
            ->lockForUpdate()
            ->first();

        if ($project === null) {
            return null;
        }

        $superseded = AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::InspectProject)
            ->where('created_at', '>', $command->created_at)
            ->exists();

        return $superseded ? null : $project;
    }
}
