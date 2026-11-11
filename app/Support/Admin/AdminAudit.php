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
    /** The `actor_id` of a `system` actor that is an operator running Artisan on the server. */
    public const CONSOLE_ACTOR_ID = 'cli';

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

    /**
     * Records an admin mutation made with an Artisan command on the server.
     * There is no signed-in account, so the actor is `system` with ID `cli`,
     * and the metadata names the operating system user that ran the command.
     * The reason may be empty only for a non-interactive run;
     * `reason_provided` says which it was.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function recordFromConsole(string $action, Model $subject, string $reason, array $metadata = []): AuditEvent
    {
        return AuditEvent::query()->create([
            'actor_type' => 'system',
            'actor_id' => self::CONSOLE_ACTOR_ID,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(),
            'ip_address' => null,
            'user_agent' => null,
            'metadata' => [
                'reason' => $reason,
                'reason_provided' => $reason !== '',
                ...$metadata,
                'os_user' => self::osUser(),
            ],
        ]);
    }

    /** The account name of the process's effective user, or its numeric ID when it has no passwd entry. */
    private static function osUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $uid = posix_geteuid();
            $entry = posix_getpwuid($uid);

            return is_array($entry) && $entry['name'] !== '' ? $entry['name'] : "uid:{$uid}";
        }

        $user = get_current_user();

        return $user !== '' ? $user : 'unknown';
    }
}
