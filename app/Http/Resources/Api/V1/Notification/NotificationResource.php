<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @property DatabaseNotification $resource
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array{id: string, category: string|null, title: string|null, message: string|null, project_id: string|null, deployment_id: string|null, invitation_id: string|null, workspace_id: string|null, read_at: string|null, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource->data;

        return [
            'id' => (string) $this->resource->id,
            'category' => is_string($data['category'] ?? null) ? $data['category'] : null,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : null,
            'message' => is_string($data['message'] ?? null) ? $data['message'] : null,
            'project_id' => is_string($data['project_id'] ?? null) ? $data['project_id'] : null,
            'deployment_id' => is_string($data['deployment_id'] ?? null) ? $data['deployment_id'] : null,
            // The invitation a `workspace` notification is about; open it with GET workspace-invitations/{id}.
            'invitation_id' => is_string($data['invitation_id'] ?? null) ? $data['invitation_id'] : null,
            // The team a `workspace` notification is about, while the recipient still belongs to it.
            'workspace_id' => is_string($data['workspace_id'] ?? null) ? $data['workspace_id'] : null,
            'read_at' => $this->resource->read_at?->toAtomString(),
            'created_at' => $this->resource->created_at?->toAtomString(),
        ];
    }
}
