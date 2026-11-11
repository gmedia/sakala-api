<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Runtime;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ShowPilotLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->requestedWorkspace();

        return $workspace === null || $this->user()->can('view', $workspace);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // Optional: without it the answer is about the caller's personal workspace.
            'workspace_id' => ['sometimes', 'uuid', Rule::exists('workspaces', 'id')],
        ];
    }

    public function requestedWorkspace(): ?Workspace
    {
        $workspaceId = $this->query('workspace_id');

        return is_string($workspaceId) && Str::isUuid($workspaceId)
            ? Workspace::query()->find($workspaceId)
            : null;
    }
}
