<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Data\Deployment\DeploymentPaginateData;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use Illuminate\Contracts\Pagination\CursorPaginator;

final class GetDeploymentEventAction
{
    /**
     * @return CursorPaginator<int, DeploymentEvent>
     */
    public function handle(
        Deployment $deployment,
        DeploymentPaginateData $data
    ): CursorPaginator {
        return DeploymentEvent::query()
            ->whereBelongsTo($deployment)
            ->orderBy('sequence')
            ->orderBy('id')
            ->cursorPaginate(
                perPage: $data->perPage,
                cursor: $data->cursor
            )
            ->withQueryString();
    }
}
