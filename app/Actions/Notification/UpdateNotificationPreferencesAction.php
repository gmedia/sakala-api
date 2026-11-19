<?php

declare(strict_types=1);

namespace App\Actions\Notification;

use App\Data\Notification\NotificationPreferencesData;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateNotificationPreferencesAction
{
    /**
     * @param  array<string, bool>  $changes  in-app, category value => enabled
     * @param  array<string, bool>  $emailChanges  email, category value => enabled
     */
    public function handle(User $user, array $changes, array $emailChanges = []): NotificationPreferencesData
    {
        return DB::transaction(function () use ($user, $changes, $emailChanges): NotificationPreferencesData {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current = $locked->notificationPreferences();

            $preferences = [...$current->preferences, ...$changes];
            $email = [...$current->email, ...$emailChanges];

            $locked->update([
                'notification_preferences' => $preferences,
                'notification_email_preferences' => $email,
            ]);

            return NotificationPreferencesData::fromStored($preferences, $email);
        });
    }
}
