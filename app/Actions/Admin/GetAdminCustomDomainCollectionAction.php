<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AdminCustomDomainFilterData;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Support\Admin\AdminSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Custom domains of every project, newest first (ADR-024). Deleted claims and claims of deleted projects are left out. */
final class GetAdminCustomDomainCollectionAction
{
    /** @return LengthAwarePaginator<int, ProjectDomain> */
    public function handle(AdminCustomDomainFilterData $data): LengthAwarePaginator
    {
        $query = ProjectDomain::query()
            ->with('project:id,name,slug,workspace_id')
            ->whereHas('project');

        AdminSearch::apply($query, ['hostname'], $data->search === null ? null : strtolower($data->search));

        return $query
            ->when($data->status !== null, fn ($query) => $query->where('status', $data->status))
            ->when($data->projectId !== null, fn ($query) => $query->where('project_id', $data->projectId))
            ->when($data->workspaceId !== null, fn ($query) => $query->whereIn('project_id', Project::query()->select('id')->where('workspace_id', $data->workspaceId)))
            ->when($data->failing === true, fn ($query) => $query->whereNotNull('failing_since'))
            ->when($data->failing === false, fn ($query) => $query->whereNull('failing_since'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $data->perPage, page: $data->page)
            ->withQueryString();
    }
}
