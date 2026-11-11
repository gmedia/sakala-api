<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\AdminAgentFilterData;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeStatus;
use App\Http\Requests\Concerns\PaginatesAdminList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexAdminAgentRequest extends FormRequest
{
    use PaginatesAdminList;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            /** Matches name, agent ID, or hostname, case-insensitively. */
            'search' => ['sometimes', 'string', 'max:255'],
            /** The status the node last reported (`offline` when its heartbeat stopped). */
            'status' => ['sometimes', 'string', Rule::enum(AgentNodeStatus::class)],
            'auth_status' => ['sometimes', 'string', Rule::enum(AgentAuthStatus::class)],
            /** `true` for nodes that never sent a heartbeat (waiting for the agent to start), `false` for the rest. */
            'never_seen' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): AdminAgentFilterData
    {
        return new AdminAgentFilterData(
            page: $this->page(),
            perPage: $this->perPage(),
            search: $this->search(),
            status: $this->filled('status') ? AgentNodeStatus::from($this->string('status')->toString()) : null,
            authStatus: $this->filled('auth_status') ? AgentAuthStatus::from($this->string('auth_status')->toString()) : null,
            neverSeen: $this->has('never_seen') ? $this->boolean('never_seen') : null,
        );
    }
}
