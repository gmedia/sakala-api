<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Data\Project\EnvironmentVariableData;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EnvironmentVariableRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()->can('update', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            //
            'key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Z_][A-Z0-9_]*$/',
                Rule::unique('environment_variables')->where(fn ($query) => $query->where('project_id', $project->id)),
            ],
            'value' => ['required', 'string'],
            'is_secret' => ['required', 'boolean'],
        ];
    }

    public function toData(): EnvironmentVariableData
    {
        /** @var Project $project */
        $project = $this->route('project');

        return new EnvironmentVariableData(
            projectId: $project->id,
            key: $this->string('key')->toString(),
            value: $this->string('value')->toString(),
            isSecret: $this->boolean('is_secret'),
        );
    }
}
