<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\AgentCommandFilterData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Http\Requests\Concerns\PaginatesAdminList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexAgentCommandRequest extends FormRequest
{
    use PaginatesAdminList;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            'type' => ['sometimes', 'string', Rule::enum(AgentCommandType::class)],
            'status' => ['sometimes', 'string', Rule::enum(AgentCommandStatus::class)],
        ];
    }

    public function toData(): AgentCommandFilterData
    {
        return new AgentCommandFilterData(
            page: $this->page(),
            perPage: $this->perPage(),
            type: $this->filled('type') ? AgentCommandType::from($this->string('type')->toString()) : null,
            status: $this->filled('status') ? AgentCommandStatus::from($this->string('status')->toString()) : null,
        );
    }
}
