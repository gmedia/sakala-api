<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationCategory;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * The in-app notification: stored for the list and pushed to `users.{id}`.
 * The stored shape is the contract of NotificationResource.
 */
final class UserAlertNotification extends Notification
{
    public function __construct(
        public readonly NotificationCategory $category,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $projectId = null,
        public readonly ?string $deploymentId = null,
        public readonly ?string $invitationId = null,
        public readonly ?string $workspaceId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** The event name the console subscribes to on `users.{id}`. */
    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastType(): string
    {
        return 'notification.created';
    }

    /** @return array{category: string, title: string, message: string, project_id: string|null, deployment_id: string|null, invitation_id: string|null, workspace_id: string|null} */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category->value,
            'title' => $this->title,
            'message' => $this->message,
            'project_id' => $this->projectId,
            'deployment_id' => $this->deploymentId,
            'invitation_id' => $this->invitationId,
            'workspace_id' => $this->workspaceId,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
