<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\AuditEvent;
use App\Support\Admin\AuditMorphAlias;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditEvent
 *
 * @property AuditEvent $resource
 */
final class AuditEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $event = $this->resource;
        $user = $event->relationLoaded('actorUser') ? $event->actorUser : null;

        return [
            'id' => $event->id,
            /** For example `project.suspend_requested`, `user.role_changed`, `workspace.limit_profile_changed`. */
            'action' => $event->action,
            'actor' => [
                /** `user`, `agent_node`, `system`, or `github_app`. */
                'type' => AuditMorphAlias::toAlias($event->actor_type),
                'id' => $event->actor_id,
                /** Name and username of a user actor that still exists, otherwise null. */
                'name' => $user?->name,
                'username' => $user?->username,
            ],
            'subject' => [
                /** `user`, `project`, `deployment`, `workspace`, `limit_profile`, `agent_node`, `agent_command`, `feedback`, `usage_signal`, or `github_installation`. */
                'type' => AuditMorphAlias::toAlias($event->subject_type),
                'id' => $event->subject_id,
            ],
            /**
             * IDs, changed values, and the admin's `reason`. Never secret values or tokens.
             */
            'metadata' => $event->metadata,
            /**
             * Recorded for admin actions and for secret reveals by someone outside the project's workspace.
             */
            'ip_address' => $event->ip_address,
            'user_agent' => $event->user_agent,
            'created_at' => $event->created_at?->toAtomString(),
        ];
    }
}
