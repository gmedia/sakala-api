<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Data\Runtime\PilotQuotaLimitsData;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

final readonly class AdminUserDetailData
{
    /**
     * @param  Collection<int, Workspace>  $workspaces  Loaded through User::workspaces(), with `projects_count`.
     * @param  Collection<int, AuditEvent>  $recentAuditEvents
     */
    public function __construct(
        public User $user,
        public Collection $workspaces,
        public ?PilotQuotaLimitsData $personalQuota,
        public int $ownedTeamsCount,
        public Collection $recentAuditEvents,
    ) {}
}
