<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\AgentCommandType;
use App\Enums\ProjectStatus;
use App\Models\AgentCommand;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

final class ProjectCommandEligibilityService
{
    /**
     * @param  Builder<AgentCommand>  $query
     * @return Builder<AgentCommand>
     */
    public function applyToQuery(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('project_id')
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('type', '!=', AgentCommandType::DeployProject)
                        ->orWhereHas('project', function (Builder $query): void {
                            $query->where(
                                'status',
                                '!=',
                                ProjectStatus::Suspended,
                            );
                        });
                });
        });
    }

    public function isEligible(
        Project $project,
        AgentCommandType $type,
    ): bool {
        return ! (
            $type === AgentCommandType::DeployProject
            && $project->status === ProjectStatus::Suspended
        );
    }
}
