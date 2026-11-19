<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Notification;

use App\Enums\NotificationCategory;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateNotificationPreferencesRequest extends FormRequest
{
    public const string EMAIL = 'email';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $rules = [];

        foreach (NotificationCategory::cases() as $category) {
            $rules[$category->value] = ['sometimes', 'boolean'];
        }

        // Only categories with an email channel; any other key is rejected.
        $rules[self::EMAIL] = ['sometimes', 'array:'.implode(',', NotificationCategory::emailableValues())];

        foreach (NotificationCategory::emailable() as $category) {
            $rules[self::EMAIL.'.'.$category->value] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /** @return array<string, bool> In-app changes, category value => enabled. */
    public function changes(): array
    {
        $validated = $this->validated();
        unset($validated[self::EMAIL]);

        return array_map(static fn (mixed $value): bool => (bool) $value, $validated);
    }

    /** @return array<string, bool> Email changes, category value => enabled. */
    public function emailChanges(): array
    {
        $email = $this->validated(self::EMAIL, []);

        return array_map(static fn (mixed $value): bool => (bool) $value, is_array($email) ? $email : []);
    }
}
