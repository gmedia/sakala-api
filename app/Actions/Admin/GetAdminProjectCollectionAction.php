<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AdminProjectFilterData;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Admin\AdminSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Every workspace's projects. Deleted projects waiting for purge are left out. */
final class GetAdminProjectCollectionAction
{
    /** @return LengthAwarePaginator<int, Project> */
    public function handle(AdminProjectFilterData $data): LengthAwarePaginator
    {
        $query = Project::query()
            ->with(['latestDeployment', 'latestSuspendRequest', 'workspace', 'user:id,name,username'])
            ->withIdleSleepBlocked();

        AdminSearch::apply($query, ['name', 'slug', 'repository_full_name'], $data->search);

        return $query
            ->when($data->status !== null, fn ($query) => $query->where('status', $data->status))
            ->when($data->runtimeStatus !== null, fn ($query) => $query->where('runtime_status', $data->runtimeStatus))
            ->when($data->workspaceId !== null, fn ($query) => $query->where('workspace_id', $data->workspaceId))
            ->when($data->suspended === true, fn ($query) => $query->where('status', ProjectStatus::Suspended))
            ->when($data->suspended === false, fn ($query) => $query->where('status', '!=', ProjectStatus::Suspended))
            ->when($data->idleSleepBlocked !== null, fn ($query) => $query->idleSleepBlocked((bool) $data->idleSleepBlocked))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $data->perPage, page: $data->page)
            ->withQueryString();
    }
}
