<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Data\Project\DomainPreviewData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property DomainPreviewData $resource
 */
final class DomainPreviewResource extends JsonResource
{
    /** @return array{slug: string, domain: string} */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->resource->slug,
            'domain' => $this->resource->domain,
        ];
    }
}
