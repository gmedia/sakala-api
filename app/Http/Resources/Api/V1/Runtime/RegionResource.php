<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Runtime;

use App\Data\Runtime\RegionData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A region a project may run in.
 *
 * @property RegionData $resource
 */
final class RegionResource extends JsonResource
{
    /** @return array{slug: string} */
    public function toArray(Request $request): array
    {
        return [
            /** The value a project's `region` or a profile's `default_region` takes, for example `jkt`. */
            'slug' => $this->resource->slug,
        ];
    }
}
