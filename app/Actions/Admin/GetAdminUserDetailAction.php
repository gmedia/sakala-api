<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AdminUserDetailData;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\Admin\AuditActorLoader;
use Illuminate\Database\Eloquent\Builder;

final class GetAdminUserDetailAction
{
    public const RECENT_AUDIT_EVENTS = 20;

    public function __construct(private readonly PilotRuntimeLimitService $limits) {}

    public function handle(User $user): AdminUserDetailData
    {
        $user->loadCount('projects');

        $workspaces = $user->workspaces()
            ->with('limitProfile')
            ->withCount('projects')
            ->orderByRaw("CASE WHEN workspaces.type = 'personal' THEN 0 ELSE 1 END")
            ->orderBy('workspaces.name')
            ->get();

        $personal = $workspaces->first(fn ($workspace): bool => $workspace->type === WorkspaceType::Personal);

        // Uses the (actor_type, actor_id, created_at) and (subject_type,
        // subject_id, created_at) indexes, one per branch of the OR.
        $events = AuditEvent::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('actor_type', User::class)->where('actor_id', (string) $user->id))
                ->orWhere(fn (Builder $query) => $query->where('subject_type', User::class)->where('subject_id', (string) $user->id)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_AUDIT_EVENTS)
            ->get();

        AuditActorLoader::load($events);

        return new AdminUserDetailData(
            user: $user,
            workspaces: $workspaces,
            personalQuota: $personal === null ? null : $this->limits->getPilotQuotaLimits($personal),
            ownedTeamsCount: WorkspaceMember::query()
                ->where('user_id', $user->id)
                ->where('role', WorkspaceRole::Owner)
                ->whereHas('workspace', fn (Builder $query) => $query->where('type', WorkspaceType::Team))
                ->count(),
            recentAuditEvents: $events,
        );
    }
}
