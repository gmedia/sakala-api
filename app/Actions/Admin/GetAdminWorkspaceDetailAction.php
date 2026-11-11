<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AdminWorkspaceDetailData;
use App\Models\Workspace;
use App\Services\Runtime\PilotRuntimeLimitService;

/**
 * Members and projects are listed whole: a workspace holds at most its
 * profile's project limit and a handful of members.
 */
final class GetAdminWorkspaceDetailAction
{
    public function __construct(private readonly PilotRuntimeLimitService $limits) {}

    public function handle(Workspace $workspace): AdminWorkspaceDetailData
    {
        $workspace->load(['limitProfile', 'personalUser:id,name,username'])->loadCount(['members', 'projects']);

        return new AdminWorkspaceDetailData(
            workspace: $workspace,
            members: $workspace->members()->with('user')->orderBy('created_at')->orderBy('id')->get(),
            projects: $workspace->projects()->orderByDesc('created_at')->orderByDesc('id')->get(),
            quota: $this->limits->getPilotQuotaLimits($workspace),
        );
    }
}
