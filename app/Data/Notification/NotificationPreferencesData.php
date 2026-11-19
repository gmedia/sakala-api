<?php

declare(strict_types=1);

namespace App\Data\Notification;

use App\Enums\NotificationCategory;

final readonly class NotificationPreferencesData
{
    /**
     * @param  array<string, bool>  $preferences  category value => in-app notification on
     * @param  array<string, bool>  $email  category value => email on, only for categories with an email channel
     */
    public function __construct(
        public array $preferences,
        public array $email,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $stored
     * @param  array<array-key, mixed>|null  $storedEmail
     */
    public static function fromStored(?array $stored, ?array $storedEmail = null): self
    {
        $preferences = [];
        $email = [];

        foreach (NotificationCategory::cases() as $category) {
            $preferences[$category->value] = ($stored[$category->value] ?? true) !== false;

            $emailDefault = $category->emailDefault();

            if ($emailDefault !== null) {
                $email[$category->value] = (bool) ($storedEmail[$category->value] ?? $emailDefault);
            }
        }

        return new self($preferences, $email);
    }
}
