<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Data\Project\CreateProjectData;
use App\Models\Project;
use App\Rules\GithubRepositoryUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectRequest extends FormRequest
{
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
            'name' => ['required',  'string', 'max:120'],
            'repository_url' => ['required', 'url', 'max:255', new GithubRepositoryUrl],
            'branch' => ['required', 'string', 'max:255'],
        ];
    }

    public function toData(): CreateProjectData
    {
        $name = $this->validated('name');
        $repository_url = $this->validated('repository_url');
        $branch = $this->validated('branch');

        return new CreateProjectData(
            name: $name,
            repository_url: $repository_url,
            branch: $branch,
        );
    }
}
