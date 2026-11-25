<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Valkey;

use App\Models\ValkeyAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ValkeyAttachment
 *
 * @property ValkeyAttachment $resource
 */
final class ValkeyAttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->resource->project;

        return [
            'valkey_id' => $this->resource->valkey_id,
            'project' => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug],
            /** True while the project's serving deployment lacks the store's network and variables. Deploy again to apply them. */
            'redeploy_required' => $project->requiresRedeploy(),
            'attached_at' => $this->resource->created_at?->toAtomString(),
        ];
    }
}
