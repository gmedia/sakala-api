<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Database;

use App\Models\DatabaseAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseAttachment
 *
 * @property DatabaseAttachment $resource
 */
final class DatabaseAttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->resource->project;

        return [
            'database_id' => $this->resource->database_id,
            'project' => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug],
            /** True while the project's serving deployment lacks the connection variables. Deploy again to apply them. */
            'redeploy_required' => $project->requiresRedeploy(),
            'attached_at' => $this->resource->created_at?->toAtomString(),
        ];
    }
}
