<?php

declare(strict_types=1);

namespace App\Actions\Notification;

use App\Enums\NotificationCategory;
use App\Models\User;

/** Turns off one category's email for the user a signed link names. Idempotent. */
final class UnsubscribeNotificationEmailAction
{
    public function __construct(
        private readonly UpdateNotificationPreferencesAction $updatePreferences,
    ) {}

    public function handle(int $userId, NotificationCategory $category): void
    {
        $user = User::query()->findOrFail($userId);

        $this->updatePreferences->handle($user, [], [$category->value => false]);
    }
}
