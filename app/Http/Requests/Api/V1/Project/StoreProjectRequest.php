<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Data\Project\CreateProjectData;
use App\Enums\GithubRepositorySource;
use App\Models\Project;
use App\Rules\GithubRepositoryUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('repository') || ! $this->has('repository_url')) {
            return;
        }

        $this->merge(['repository' => [
            'type' => GithubRepositorySource::PublicUrl->value,
            'url' => $this->input('repository_url'),
        ]]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Project::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
            'name' => ['required', 'string', 'max:120'],
            'repository' => ['required', 'array'],
            'repository.type' => ['required', 'string', 'in:public_url,github_installation'],
            'repository.url' => ['bail', 'required_if:repository.type,public_url', 'prohibited_unless:repository.type,public_url', 'string', 'url', 'max:255', new GithubRepositoryUrl],
            'repository.installation_id' => ['required_if:repository.type,github_installation', 'prohibited_unless:repository.type,github_installation', 'uuid'],
            'repository.repository_id' => ['required_if:repository.type,github_installation', 'prohibited_unless:repository.type,github_installation', 'integer', 'min:1'],
            'branch' => ['required', 'string', 'max:255'],
        ];
    }

    public function toData(): CreateProjectData
    {
        $name = $this->validated('name');
        $branch = $this->validated('branch');
        $repository = $this->validated('repository');

        return new CreateProjectData(
            name: $name,
            branch: $branch,
            repositorySource: GithubRepositorySource::from($repository['type']),
            repositoryUrl: $repository['url'] ?? null,
            githubInstallationId: $repository['installation_id'] ?? null,
            githubRepositoryId: isset($repository['repository_id']) ? (int) $repository['repository_id'] : null,
        );
    }
}
