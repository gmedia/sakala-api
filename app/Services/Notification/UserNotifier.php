<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\UserAlertNotification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivers in-app notifications after the surrounding transaction commits, so
 * a rollback never leaves a notification about something that did not happen.
 * A delivery problem is reported, never raised: the agent report or request
 * that caused it has already been committed and must still succeed.
 */
final class UserNotifier
{
    public function send(
        User $user,
        NotificationCategory $category,
        string $title,
        string $message,
        ?string $projectId = null,
        ?string $deploymentId = null,
    ): void {
        if (! $user->wantsNotification($category)) {
            return;
        }

        DB::afterCommit(static function () use ($user, $category, $title, $message, $projectId, $deploymentId): void {
            try {
                $user->notify(new UserAlertNotification($category, $title, $message, $projectId, $deploymentId));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
