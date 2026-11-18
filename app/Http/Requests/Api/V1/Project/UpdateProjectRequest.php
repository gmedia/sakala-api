<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Data\Project\UpdateProjectData;
use App\Http\Requests\Concerns\ValidatesBuildConfiguration;
use App\Models\Project;
use App\Services\Runtime\PilotRuntimeLimitService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateProjectRequest extends FormRequest
{
    use ValidatesBuildConfiguration {
        messages as buildConfigurationMessages;
    }

    protected function receivesPushWebhooks(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $project->github_installation_id !== null;
    }

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
            ...$this->buildConfigurationRules(),
            /**
             * Containers the project runs behind the node's router, from 1 to `max_replicas` of its workspace.
             * Applies from the next deployment; `redeploy_required` turns true until then.
             */
            'replicas' => [
                'sometimes',
                'integer',
                'min:'.Project::DEFAULT_REPLICAS,
                // A closure, so the documented schema does not show the maximum
                // of whichever workspace was resolved while generating it.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $max = $this->maxReplicas();

                    if (is_numeric($value) && (int) $value > $max) {
                        $fail("Proyek di workspace ini paling banyak berjalan dengan {$max} replika. Minta admin menaikkan batasnya bila butuh lebih.");
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->buildConfigurationMessages(),
            'replicas.min' => 'Jumlah replika paling sedikit :min.',
        ];
    }

    private function maxReplicas(): int
    {
        $project = $this->route('project');

        return app(PilotRuntimeLimitService::class)->maxReplicasIn($project instanceof Project ? $project->workspace : null);
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
            buildConfiguration: $this->buildConfiguration(),
            autoDeploy: array_key_exists('auto_deploy', $validated) ? $this->boolean('auto_deploy') : null,
            replicas: array_key_exists('replicas', $validated) ? $this->integer('replicas') : null,
        );
    }
}
