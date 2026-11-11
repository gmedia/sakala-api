<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetAdminOverviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminOverviewRequest;
use App\Http\Resources\Api\V1\Admin\AdminOverviewResource;

final class OverviewController extends Controller
{
    /**
     * Platform overview for the admin dashboard.
     *
     * Totals, in-range counts with the previous window for trends, daily user growth in WIB, deployment outcomes,
     * failure categories and codes, new-user sources, and pilot validation metrics. Cached for 60 seconds.
     */
    public function __invoke(AdminOverviewRequest $request, GetAdminOverviewAction $action): AdminOverviewResource
    {
        return new AdminOverviewResource($action->handle($request->toData()));
    }
}
