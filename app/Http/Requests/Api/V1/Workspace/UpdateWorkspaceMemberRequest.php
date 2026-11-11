<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateWorkspaceMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->route('workspace');

        return $workspace instanceof Workspace && $this->user()->can('manageMembers', $workspace);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(WorkspaceRole::class)],
        ];
    }

    public function role(): WorkspaceRole
    {
        return WorkspaceRole::from((string) $this->validated('role'));
    }
}
