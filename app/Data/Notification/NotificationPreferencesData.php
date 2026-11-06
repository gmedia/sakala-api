<?php

declare(strict_types=1);

namespace App\Data\Notification;

use App\Enums\NotificationCategory;

final readonly class NotificationPreferencesData
{
    /** @param array<string, bool> $preferences category value => enabled */
    public function __construct(
        public array $preferences,
    ) {}

    /** @param array<array-key, mixed>|null $stored */
    public static function fromStored(?array $stored): self
    {
        $preferences = [];

        foreach (NotificationCategory::cases() as $category) {
            $preferences[$category->value] = ($stored[$category->value] ?? true) !== false;
        }

        return new self($preferences);
    }
}
