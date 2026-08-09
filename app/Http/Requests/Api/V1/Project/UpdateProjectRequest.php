<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Data\Project\UpdateProjectData;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Project|null $project */
        $project = $this->route('project');

        return $project !== null && $this->user()?->can('update', $project) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'thumbnail_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'branch' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }

    public function toData(): UpdateProjectData
    {
        $validated = $this->validated();

        /** @var string|null $name */
        $name = $this->validated('name');

        /** @var string|null $thumbnailUrl */
        $thumbnailUrl = $this->validated('thumbnail_url');

        /** @var string|null $branch */
        $branch = $this->validated('branch');

        return new UpdateProjectData(
            name: $name,
            thumbnailUrl: $thumbnailUrl,
            branch: $branch,
            thumbnailUrlProvided: array_key_exists('thumbnail_url', $validated),
        );
    }
}
