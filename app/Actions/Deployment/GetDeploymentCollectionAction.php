<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Data\Deployment\GetDeploymentCollectionData;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class GetDeploymentCollectionAction
{
    /**
     * @return LengthAwarePaginator<int, Deployment>
     */
    public function handle(
        Project $project,
        GetDeploymentCollectionData $data
    ): LengthAwarePaginator {
        $query = Deployment::query()
            ->whereBelongsTo($project)
            ->when(
                filled($data->search),
                fn ($query) => $query->where('sequence', (int) $data->search)
            );

        match ($data->filter) {
            '7_days' => $query->where('created_at', '>=', now()->subDays(7)),
            '30_days' => $query->where('created_at', '>=', now()->subDays(30)),
            default => null,
        };

        return $query
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->paginate(
                perPage: $data->perPage,
                page: $data->page
            )
            ->withQueryString();
    }
}
