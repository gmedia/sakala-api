<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\AdminWorkspaceFilterData;
use App\Enums\WorkspaceType;
use App\Http\Requests\Concerns\PaginatesAdminList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexAdminWorkspaceRequest extends FormRequest
{
    use PaginatesAdminList;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            /** Matches name or slug, case-insensitively. */
            'search' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::enum(WorkspaceType::class)],
            /** Only workspaces running under this limit profile. */
            'limit_profile_id' => ['sometimes', 'uuid'],
        ];
    }

    public function toData(): AdminWorkspaceFilterData
    {
        return new AdminWorkspaceFilterData(
            page: $this->page(),
            perPage: $this->perPage(),
            search: $this->search(),
            type: $this->filled('type') ? WorkspaceType::from($this->string('type')->toString()) : null,
            limitProfileId: $this->filled('limit_profile_id') ? $this->string('limit_profile_id')->toString() : null,
        );
    }
}
