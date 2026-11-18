<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Runtime;

use App\Actions\Project\GetAvailableRegionsAction;
use App\Data\Runtime\RegionData;
use App\Http\Resources\Api\V1\Runtime\RegionResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RegionController
{
    /**
     * Regions a project may run in: those of runtime nodes that are allowed
     * work, sorted. Empty while no node reports a region; projects then keep
     * `region` null and run anywhere.
     */
    public function index(GetAvailableRegionsAction $action): AnonymousResourceCollection
    {
        return RegionResource::collection(array_map(
            static fn (string $slug): RegionData => new RegionData($slug),
            $action->handle(),
        ));
    }
}
