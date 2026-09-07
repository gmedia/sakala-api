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
        $blockedTypes = array_map(
            static fn (AgentCommandType $type): string => $type->value,
            array_filter(
                AgentCommandType::cases(),
                static fn (AgentCommandType $type): bool => $type->isBlockedForSuspendedProject(),
            ),
        );

        return $query->where(function (Builder $query) use ($blockedTypes): void {
            $query
                ->whereNull('project_id')
                ->orWhere(function (Builder $query) use ($blockedTypes): void {
                    $query
                        ->whereNotIn('type', $blockedTypes)
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
        if ($project->status !== ProjectStatus::Suspended) {
            return true;
        }

        return ! $type->isBlockedForSuspendedProject();
    }
}
