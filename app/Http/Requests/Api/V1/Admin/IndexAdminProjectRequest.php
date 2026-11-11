<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\AdminProjectFilterData;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Http\Requests\Concerns\PaginatesAdminList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexAdminProjectRequest extends FormRequest
{
    use PaginatesAdminList;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            /** Matches name, slug, or `owner/repository`, case-insensitively. */
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::enum(ProjectStatus::class)],
            'runtime_status' => ['sometimes', 'string', Rule::enum(RuntimeStatus::class)],
            'workspace_id' => ['sometimes', 'uuid'],
            /** `true` for suspended projects only, `false` to leave them out. */
            'suspended' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): AdminProjectFilterData
    {
        return new AdminProjectFilterData(
            page: $this->page(),
            perPage: $this->perPage(),
            search: $this->search(),
            status: $this->filled('status') ? ProjectStatus::from($this->string('status')->toString()) : null,
            runtimeStatus: $this->filled('runtime_status') ? RuntimeStatus::from($this->string('runtime_status')->toString()) : null,
            workspaceId: $this->filled('workspace_id') ? $this->string('workspace_id')->toString() : null,
            suspended: $this->has('suspended') ? $this->boolean('suspended') : null,
        );
    }
}
