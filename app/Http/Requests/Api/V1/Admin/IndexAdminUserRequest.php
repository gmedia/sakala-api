<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\AdminUserFilterData;
use App\Enums\UserRole;
use App\Http\Requests\Concerns\PaginatesAdminList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexAdminUserRequest extends FormRequest
{
    use PaginatesAdminList;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            /** Matches name, username, or email, case-insensitively. */
            'search' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'string', Rule::enum(UserRole::class)],
            /** `true` for verified email addresses only, `false` for unverified only. */
            'verified' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): AdminUserFilterData
    {
        return new AdminUserFilterData(
            page: $this->page(),
            perPage: $this->perPage(),
            search: $this->search(),
            role: $this->filled('role') ? UserRole::from($this->string('role')->toString()) : null,
            verified: $this->has('verified') ? $this->boolean('verified') : null,
        );
    }
}
