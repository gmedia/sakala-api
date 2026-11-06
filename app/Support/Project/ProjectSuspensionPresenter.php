<?php

declare(strict_types=1);

namespace App\Support\Project;

use App\Enums\ProjectStatus;
use App\Models\Project;

/**
 * Why a project is suspended, for whoever may already see the project. `status`
 * decides, so lifting a suspension clears this while its record stays for audit.
 */
final class ProjectSuspensionPresenter
{
    /**
     * Both values are null for a suspended project without a suspend record.
     *
     * @return array{reason: string|null, suspended_at: string|null}|null
     */
    public static function present(Project $project): ?array
    {
        if ($project->status !== ProjectStatus::Suspended) {
            return null;
        }

        $request = $project->loadMissing('latestSuspendRequest')->latestSuspendRequest;

        return [
            'reason' => $request?->reason,
            'suspended_at' => $request?->created_at?->toAtomString(),
        ];
    }
}
