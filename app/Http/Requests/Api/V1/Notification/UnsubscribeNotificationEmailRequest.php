<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Notification;

use App\Enums\NotificationCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query of a signed unsubscribe link. The `signed` middleware has already
 * proved it came from an email Sakala sent; there is no session to check.
 */
final class UnsubscribeNotificationEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'user' => ['required', 'integer', 'min:1'],
            'category' => ['required', 'string', Rule::in(NotificationCategory::emailableValues())],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return (array) $this->query();
    }

    public function userId(): int
    {
        return (int) $this->validated('user');
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::from((string) $this->validated('category'));
    }
}
