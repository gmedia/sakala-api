<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\FailedDeploymentFilterData;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Support\Admin\AdminSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Failed deployments across every project, newest first, on the
 * `(status, created_at)` index. The category is not stored: it is derived from
 * the failure code, so a category filter becomes the list of codes recorded on
 * failed deployments that classify into it.
 */
final class GetFailedDeploymentCollectionAction
{
    public function __construct(private readonly CountFailuresAction $failures) {}

    /** @return LengthAwarePaginator<int, Deployment> */
    public function handle(FailedDeploymentFilterData $data): LengthAwarePaginator
    {
        $query = Deployment::query()
            ->with([
                'project' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'slug', 'workspace_id', 'deleted_at']),
                'requester:id,name,username',
            ])
            ->where('status', DeploymentStatus::Failed)
            ->when($data->range->from !== null, fn (Builder $query) => $query->where('created_at', '>=', $data->range->from))
            ->where('created_at', '<', $data->range->to)
            ->when($data->code !== null, fn (Builder $query) => $query->where('failure_code', $data->code))
            ->when($data->search !== null, fn (Builder $query) => $query->whereHas(
                'project',
                fn (Builder $projects) => AdminSearch::apply($projects->withTrashed(), ['name', 'slug'], $data->search),
            ));

        if ($data->category !== null) {
            [$codes, $includesMissingCode] = $this->failures->codesFor($data->category);

            $query->where(fn (Builder $query) => $query
                ->whereIn('failure_code', $codes)
                ->when($includesMissingCode, fn (Builder $query) => $query->orWhereNull('failure_code')));
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $data->perPage, page: $data->page)
            ->withQueryString();
    }
}
