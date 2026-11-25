<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\AdminCustomDomainFilterData;
use App\Enums\CustomDomainStatus;
use App\Http\Requests\Concerns\PaginatesAdminList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexAdminCustomDomainRequest extends FormRequest
{
    use PaginatesAdminList;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            /** Matches the hostname, case-insensitively. */
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::enum(CustomDomainStatus::class)],
            'project_id' => ['sometimes', 'uuid'],
            'workspace_id' => ['sometimes', 'uuid'],
            /** `true` for verified domains whose checks are failing (`failing_since` set), `false` to leave them out. */
            'failing' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): AdminCustomDomainFilterData
    {
        return new AdminCustomDomainFilterData(
            page: $this->page(),
            perPage: $this->perPage(),
            search: $this->search(),
            status: $this->filled('status') ? CustomDomainStatus::from($this->string('status')->toString()) : null,
            projectId: $this->filled('project_id') ? $this->string('project_id')->toString() : null,
            workspaceId: $this->filled('workspace_id') ? $this->string('workspace_id')->toString() : null,
            failing: $this->has('failing') ? $this->boolean('failing') : null,
        );
    }
}
