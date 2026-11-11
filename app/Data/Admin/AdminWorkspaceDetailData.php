<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Data\Runtime\PilotQuotaLimitsData;
use App\Models\Project;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Collection;

final readonly class AdminWorkspaceDetailData
{
    /**
     * @param  Collection<int, WorkspaceMember>  $members
     * @param  Collection<int, Project>  $projects
     */
    public function __construct(
        public Workspace $workspace,
        public Collection $members,
        public Collection $projects,
        public PilotQuotaLimitsData $quota,
    ) {}
}
