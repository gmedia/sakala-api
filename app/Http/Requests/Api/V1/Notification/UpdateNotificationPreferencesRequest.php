<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Notification;

use App\Enums\NotificationCategory;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateNotificationPreferencesRequest extends FormRequest
{
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

        return $rules;
    }

    /** @return array<string, bool> */
    public function changes(): array
    {
        return array_map(static fn (mixed $value): bool => (bool) $value, $this->validated());
    }
}
