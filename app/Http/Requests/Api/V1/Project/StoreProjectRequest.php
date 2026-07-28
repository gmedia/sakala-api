<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Data\Project\CreateProjectData;
use App\Support\Domains\ProjectDomainGenerator;
use App\Support\Slug\GenerateSlug;
use App\Support\Slug\ReservedSlug;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'repository_url' => ['required', 'url', 'max:255'],
            'branch' => ['required', 'string', 'max:255'],
            'repository_provider' => ['nullable', 'string', 'max:50'],
            'repository_full_name' => ['nullable', 'string', 'max:255'],
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
            'name.required' => 'The project name is required.',
            'name.string' => 'The project name must be a string.',
            'name.max' => 'The project name may not be greater than 255 characters.',
            'repository_url.required' => 'The repository URL is required.',
            'repository_url.url' => 'The repository URL must be a valid URL.',
            'repository_url.max' => 'The repository URL may not be greater than 255 characters.',
            'branch.required' => 'The branch is required.',
            'branch.string' => 'The branch must be a string.',
            'branch.max' => 'The branch may not be greater than 255 characters.',
            'repository_provider.string' => 'The repository provider must be a string.',
            'repository_provider.max' => 'The repository provider may not be greater than 50 characters.',
            'repository_full_name.string' => 'The repository full name must be a string.',
            'repository_full_name.max' => 'The repository full name may not be greater than 255 characters.',
        ];
    }

    /**
     * Create a CreateProjectData instance from the validated request.
     */
    public function toData(): CreateProjectData
    {
        return new CreateProjectData(
            name: $this->string('name')->value(),
            repositoryUrl: $this->string('repository_url')->value(),
            branch: $this->string('branch')->value(),
            repositoryProvider: $this->has('repository_provider') ? $this->string('repository_provider')->value() : null,
            repositoryFullName: $this->has('repository_full_name') ? $this->string('repository_full_name')->value() : null,
        );
    }

    /**
     * Generate a unique slug for the project.
     */
    public function generateSlug(GenerateSlug $generator, ReservedSlug $reservedSlug): string
    {
        $slug = $generator->fromString($this->string('name')->value());

        // Ensure slug is not reserved
        if ($reservedSlug->isReserved($slug)) {
            $slug = $generator->fromString($this->string('name')->value().'-'.substr(md5(microtime()), 0, 8));
        }

        return $slug;
    }

    /**
     * Generate the default domain for the project.
     */
    public function generateDefaultDomain(ProjectDomainGenerator $domainGenerator, string $slug): string
    {
        return $domainGenerator->generate($slug);
    }
}
