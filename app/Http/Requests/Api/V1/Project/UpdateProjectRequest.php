<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Data\Project\UpdateProjectData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'repository_url' => ['sometimes', 'url', 'max:255'],
            'branch' => ['sometimes', 'string', 'max:255'],
            'repository_provider' => ['sometimes', 'string', 'max:50'],
            'repository_full_name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.string' => 'The project name must be a string.',
            'name.max' => 'The project name may not be greater than 255 characters.',
            'repository_url.url' => 'The repository URL must be a valid URL.',
            'repository_url.max' => 'The repository URL may not be greater than 255 characters.',
            'branch.string' => 'The branch must be a string.',
            'branch.max' => 'The branch may not be greater than 255 characters.',
            'repository_provider.string' => 'The repository provider must be a string.',
            'repository_provider.max' => 'The repository provider may not be greater than 50 characters.',
            'repository_full_name.string' => 'The repository full name must be a string.',
            'repository_full_name.max' => 'The repository full name may not be greater than 255 characters.',
        ];
    }

    /**
     * Create an UpdateProjectData instance from the validated request.
     */
    public function toData(): UpdateProjectData
    {
        return new UpdateProjectData(
            name: $this->has('name') ? $this->string('name')->value() : null,
            repositoryUrl: $this->has('repository_url') ? $this->string('repository_url')->value() : null,
            branch: $this->has('branch') ? $this->string('branch')->value() : null,
            repositoryProvider: $this->has('repository_provider') ? $this->string('repository_provider')->value() : null,
            repositoryFullName: $this->has('repository_full_name') ? $this->string('repository_full_name')->value() : null,
        );
    }
}
