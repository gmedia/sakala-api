<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetAuditEventCollectionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\IndexAuditEventRequest;
use App\Http\Resources\Api\V1\Admin\AuditEventResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AuditEventController extends Controller
{
    /**
     * Audit events, newest first. Without `range` every event is listed.
     */
    public function __invoke(IndexAuditEventRequest $request, GetAuditEventCollectionAction $action): AnonymousResourceCollection
    {
        return AuditEventResource::collection($action->handle($request->toData()));
    }
}
