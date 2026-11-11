<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Workspace;

use App\Support\Workspace\WorkspaceSlugGenerator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // Optional: generated from the name when omitted.
            'slug' => ['sometimes', 'string', 'max:'.WorkspaceSlugGenerator::MAX_LENGTH, 'regex:'.WorkspaceSlugGenerator::PATTERN, Rule::unique('workspaces', 'slug')],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function slug(): ?string
    {
        $slug = $this->validated('slug');

        return is_string($slug) ? $slug : null;
    }
}
