<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AgentCommandFilterData;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Support\Admin\AgentCommandColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** One node's commands, newest first, on the `(agent_node_id, created_at)` index. */
final class GetAgentCommandCollectionAction
{
    /** @return LengthAwarePaginator<int, AgentCommand> */
    public function handle(AgentNode $node, AgentCommandFilterData $data): LengthAwarePaginator
    {
        return AgentCommand::query()
            ->select(AgentCommandColumns::SAFE)
            ->with(['project' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'slug', 'deleted_at'])])
            ->withCount('reports')
            ->where('agent_node_id', $node->id)
            ->when($data->type !== null, fn (Builder $query) => $query->where('type', $data->type))
            ->when($data->status !== null, fn (Builder $query) => $query->where('status', $data->status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $data->perPage, page: $data->page)
            ->withQueryString();
    }
}
