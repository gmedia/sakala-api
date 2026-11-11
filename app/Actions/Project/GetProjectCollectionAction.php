<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\GetProjectCollectionData;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class GetProjectCollectionAction
{
    /**
     * @return LengthAwarePaginator<int, Project>
     */
    public function handle(
        User $user,
        GetProjectCollectionData $data
    ): LengthAwarePaginator {
        // Projects of the workspaces the user belongs to, or of the one asked
        // for. A solo user belongs only to the personal workspace, so the list
        // is what it was before workspaces.
        $query = Project::query()
            ->with(['latestDeployment', 'latestSuspendRequest', 'workspace'])
            ->whereIn('workspace_id', WorkspaceMember::query()->select('workspace_id')->where('user_id', $user->id))
            ->when($data->workspaceId !== null, fn ($query) => $query->where('workspace_id', $data->workspaceId))
            ->when(
                filled($data->search),
                fn ($query) => $query->where('name', 'ILIKE', "%{$data->search}%")
            );

        match ($data->filter) {
            '7_days' => $query->where('created_at', '>=', now()->subDays(7)),
            '30_days' => $query->where('created_at', '>=', now()->subDays(30)),
            '90_days' => $query->where('created_at', '>=', now()->subDays(90)),
            default => null,
        };

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(
                perPage: $data->perPage,
                page: $data->page
            )
            ->withQueryString();
    }
}
