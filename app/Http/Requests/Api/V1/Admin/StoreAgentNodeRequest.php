<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Agent\CreateAgentData;
use App\Http\Requests\Concerns\RequiresAdminReason;
use Illuminate\Foundation\Http\FormRequest;

final class StoreAgentNodeRequest extends FormRequest
{
    use RequiresAdminReason;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->reasonRules(),
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toData(): CreateAgentData
    {
        return new CreateAgentData(
            name: $this->string('name')->toString(),
            description: $this->filled('description') ? $this->string('description')->toString() : null,
        );
    }
}
