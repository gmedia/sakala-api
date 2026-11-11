<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AdminAgentFilterData;
use App\Enums\AgentCommandStatus;
use App\Models\AgentNode;
use App\Models\Project;
use App\Support\Admin\AdminSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class GetAdminAgentCollectionAction
{
    /** @return LengthAwarePaginator<int, AgentNode> */
    public function handle(AdminAgentFilterData $data): LengthAwarePaginator
    {
        $query = self::withWorkload(AgentNode::query());

        AdminSearch::apply($query, ['name', 'agent_id', 'hostname'], $data->search);

        return $query
            ->when($data->status !== null, fn (Builder $query) => $query->where('status', $data->status))
            ->when($data->authStatus !== null, fn (Builder $query) => $query->where('auth_status', $data->authStatus))
            ->when($data->neverSeen === true, fn (Builder $query) => $query->whereNull('last_seen_at'))
            ->when($data->neverSeen === false, fn (Builder $query) => $query->whereNotNull('last_seen_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $data->perPage, page: $data->page)
            ->withQueryString();
    }

    /**
     * Adds `active_workload_count` (projects whose serving deployment runs on
     * the node, deleted projects still being stopped included) and
     * `open_commands_count` (Pending, Claimed, or Running).
     *
     * @param  Builder<AgentNode>  $query
     * @return Builder<AgentNode>
     */
    public static function withWorkload(Builder $query): Builder
    {
        return $query->withCount([
            'deployments as active_workload_count' => fn (Builder $deployments) => $deployments->whereIn(
                'deployments.id',
                Project::query()->withTrashed()->select('active_deployment_id')->whereNotNull('active_deployment_id'),
            ),
            'commands as open_commands_count' => fn (Builder $commands) => $commands->whereIn('status', [
                AgentCommandStatus::Pending,
                AgentCommandStatus::Claimed,
                AgentCommandStatus::Running,
            ]),
        ]);
    }
}
