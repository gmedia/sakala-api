<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Data\Deployment\DeploymentPaginateData;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class GetDeploymentEventAction
{
    /**
     * @return LengthAwarePaginator<int, DeploymentEvent>
     */
    public function handle(
        Deployment $deployment,
        DeploymentPaginateData $data
    ): LengthAwarePaginator {
        return DeploymentEvent::query()
            ->whereBelongsTo($deployment)
            ->orderBy('sequence')
            ->orderBy('id')
            ->paginate(
                perPage: $data->perPage,
                page: $data->page
            )
            ->withQueryString();
    }
}
