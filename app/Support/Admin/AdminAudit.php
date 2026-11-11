<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Records an admin mutation with the admin's reason, as the project control
 * actions already do. Metadata holds IDs, values that changed, and the reason;
 * never a secret, a token, or an email address.
 */
final class AdminAudit
{
    /** @param  array<string, mixed>  $metadata */
    public static function record(User $actor, string $action, Model $subject, string $reason, array $metadata = []): AuditEvent
    {
        return AuditEvent::query()->create([
            'actor_type' => User::class,
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => ['reason' => $reason, ...$metadata],
        ]);
    }
}
