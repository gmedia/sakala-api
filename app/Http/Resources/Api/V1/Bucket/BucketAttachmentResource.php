<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Bucket;

use App\Models\BucketAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BucketAttachment
 *
 * @property BucketAttachment $resource
 */
final class BucketAttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->resource->project;

        return [
            'bucket_id' => $this->resource->bucket_id,
            'project' => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug],
            /** True while the project's serving deployment lacks the bucket variables. Deploy again to apply them. */
            'redeploy_required' => $project->requiresRedeploy(),
            'attached_at' => $this->resource->created_at?->toAtomString(),
        ];
    }
}
