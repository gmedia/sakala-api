<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AdminWorkspaceFilterData;
use App\Models\Workspace;
use App\Support\Admin\AdminSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class GetAdminWorkspaceCollectionAction
{
    /** @return LengthAwarePaginator<int, Workspace> */
    public function handle(AdminWorkspaceFilterData $data): LengthAwarePaginator
    {
        $query = Workspace::query()
            ->with(['limitProfile', 'personalUser:id,name,username'])
            ->withCount(['members', 'projects']);

        AdminSearch::apply($query, ['name', 'slug'], $data->search);

        return $query
            ->when($data->type !== null, fn ($query) => $query->where('type', $data->type))
            ->when($data->limitProfileId !== null, fn ($query) => $query->where('limit_profile_id', $data->limitProfileId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $data->perPage, page: $data->page)
            ->withQueryString();
    }
}
