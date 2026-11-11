<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\AdminFeedbackFilterData;
use App\Enums\FeedbackCategory;
use App\Enums\FeedbackStatus;
use App\Http\Requests\Concerns\PaginatesAdminList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexAdminFeedbackRequest extends FormRequest
{
    use PaginatesAdminList;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            /** Matches the message, case-insensitively. */
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::enum(FeedbackStatus::class)],
            'category' => ['sometimes', 'string', Rule::enum(FeedbackCategory::class)],
        ];
    }

    public function toData(): AdminFeedbackFilterData
    {
        return new AdminFeedbackFilterData(
            page: $this->page(),
            perPage: $this->perPage(),
            search: $this->search(),
            status: $this->filled('status') ? FeedbackStatus::from($this->string('status')->toString()) : null,
            category: $this->filled('category') ? FeedbackCategory::from($this->string('category')->toString()) : null,
        );
    }
}
