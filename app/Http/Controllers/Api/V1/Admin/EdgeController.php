<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetAdminEdgeCollectionAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\AdminEdgeResource;
use App\Models\EdgeToken;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class EdgeController extends Controller
{
    /**
     * Sakala Edge instances, ordered by name, with what each last reported and its health now.
     *
     * Revoked tokens are listed with `healthy: null`. Health follows the rules of `edge:check-health`
     * (docs/EDGE_API.md#pemantauan).
     */
    public function index(GetAdminEdgeCollectionAction $action): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', EdgeToken::class);

        return AdminEdgeResource::collection($action->handle());
    }
}
