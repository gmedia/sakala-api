<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Data\Deployment\DeploymentPaginateData;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Models\ProjectProcess;
use Illuminate\Contracts\Pagination\CursorPaginator;

final class GetDeploymentLogAction
{
    /**
     * @return CursorPaginator<int, DeploymentLog>
     */
    public function handle(
        Deployment $deployment,
        DeploymentPaginateData $data
    ): CursorPaginator {
        return DeploymentLog::query()
            ->whereBelongsTo($deployment)
            // `web` names the deployment's own lines, which carry no process.
            ->when($data->process === ProjectProcess::WEB_PROCESS, fn ($query) => $query->whereNull('process'))
            ->when($data->process !== null && $data->process !== ProjectProcess::WEB_PROCESS, fn ($query) => $query->where('process', $data->process))
            ->orderBy('sequence', $data->newestFirst ? 'desc' : 'asc')
            ->orderBy('id', $data->newestFirst ? 'desc' : 'asc')
            ->cursorPaginate(
                perPage: $data->perPage,
                cursor: $data->cursor
            )
            ->withQueryString();
    }
}
