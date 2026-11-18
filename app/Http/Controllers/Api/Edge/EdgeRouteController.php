<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Edge;

use App\Actions\Edge\GetEdgeRouteTableAction;
use App\Http\Resources\Api\Edge\EdgeRouteTableResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EdgeRouteController
{
    /**
     * The route table Sakala Edge pulls: which node routers serve each
     * project host. Answers `304` without a body when `If-None-Match` carries
     * the current revision. A failure is a `5xx`, never a `200` with fewer
     * routes.
     */
    public function index(Request $request, GetEdgeRouteTableAction $action): EdgeRouteTableResource|Response
    {
        $table = $action->handle();
        $etag = '"'.$table->revision.'"';

        if (in_array($etag, $request->getETags(), true)) {
            return response()->noContent(Response::HTTP_NOT_MODIFIED)
                ->setEtag($table->revision)
                ->header('Cache-Control', EdgeRouteTableResource::CACHE_CONTROL);
        }

        return new EdgeRouteTableResource($table);
    }
}
