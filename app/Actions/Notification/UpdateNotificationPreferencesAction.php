<?php

declare(strict_types=1);

namespace App\Actions\Notification;

use App\Data\Notification\NotificationPreferencesData;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateNotificationPreferencesAction
{
    /** @param array<string, bool> $changes */
    public function handle(User $user, array $changes): NotificationPreferencesData
    {
        return DB::transaction(function () use ($user, $changes): NotificationPreferencesData {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $preferences = [...$locked->notificationPreferences()->preferences, ...$changes];

            $locked->update(['notification_preferences' => $preferences]);

            return NotificationPreferencesData::fromStored($preferences);
        });
    }
}
