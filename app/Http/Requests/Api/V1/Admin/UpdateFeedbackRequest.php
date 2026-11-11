<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\FeedbackStatus;
use App\Http\Requests\Concerns\RequiresAdminReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateFeedbackRequest extends FormRequest
{
    use RequiresAdminReason;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->reasonRules(),
            'status' => ['required', 'string', Rule::enum(FeedbackStatus::class)],
        ];
    }

    public function status(): FeedbackStatus
    {
        return FeedbackStatus::from($this->string('status')->toString());
    }
}
