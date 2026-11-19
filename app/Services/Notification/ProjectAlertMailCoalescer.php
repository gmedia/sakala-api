<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\ProjectAlert;
use App\Models\ProjectAlertMailWindow;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * At most one email per user, project, and alert in each window of
 * `sakala.notifications.email_coalesce_minutes`. Alerts inside the window are
 * counted and the next email says how many there were. The row lock keeps two
 * workers reporting at once from both sending.
 */
final class ProjectAlertMailCoalescer
{
    /**
     * The number of alerts held back since the last email when this one may be
     * sent, or null when it falls inside the window and was counted instead.
     */
    public function claim(User $user, string $projectId, ProjectAlert $alert): ?int
    {
        return DB::transaction(function () use ($user, $projectId, $alert): ?int {
            $key = ['user_id' => $user->id, 'project_id' => $projectId, 'alert' => $alert->value];
            $now = now();

            ProjectAlertMailWindow::query()->insertOrIgnore([
                ...$key,
                'suppressed_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /** @var ProjectAlertMailWindow $window */
            $window = ProjectAlertMailWindow::query()->where($key)->lockForUpdate()->firstOrFail();
            $windowStart = $now->copy()->subMinutes($this->windowMinutes());

            if ($window->last_sent_at !== null && $window->last_sent_at->greaterThan($windowStart)) {
                $window->increment('suppressed_count');

                return null;
            }

            $suppressed = $window->suppressed_count;
            $window->update(['last_sent_at' => $now, 'suppressed_count' => 0]);

            return $suppressed;
        });
    }

    private function windowMinutes(): int
    {
        return max(0, (int) config('sakala.notifications.email_coalesce_minutes'));
    }
}
