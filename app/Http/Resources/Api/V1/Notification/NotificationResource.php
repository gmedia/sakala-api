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
        return [
            'id' => (string) $this->resource->id,
            'category' => self::payloadString($this->resource, 'category'),
            'title' => self::payloadString($this->resource, 'title'),
            'message' => self::payloadString($this->resource, 'message'),
            'project_id' => self::payloadString($this->resource, 'project_id'),
            'deployment_id' => self::payloadString($this->resource, 'deployment_id'),
            // The invitation a `workspace` notification is about; open it with GET workspace-invitations/{id}.
            'invitation_id' => self::payloadString($this->resource, 'invitation_id'),
            // The team a `workspace` notification is about, while the recipient still belongs to it.
            'workspace_id' => self::payloadString($this->resource, 'workspace_id'),
            'read_at' => $this->resource->read_at?->toAtomString(),
            'created_at' => $this->resource->created_at?->toAtomString(),
        ];
    }

    /**
     * A string field of the stored payload (see UserAlertNotification), or null
     * when an older row does not have it.
     */
    public static function payloadString(DatabaseNotification $notification, string $key): ?string
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
