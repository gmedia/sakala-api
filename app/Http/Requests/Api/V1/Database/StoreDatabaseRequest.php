<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Database;

use App\Data\Database\CreateDatabaseData;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Workspace;
use App\Rules\RegionSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDatabaseRequest extends FormRequest
{
    /** Owners and members of the workspace, and admins; checked before the body is validated. */
    public function authorize(): bool
    {
        return $this->user()?->can('create', [ManagedDatabase::class, $this->route('workspace')]) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            /**
             * Name people see, unique among the workspace's databases. The names on the database node are random.
             * Optional with `project_id`: the project's name, followed by ` 2`, ` 3`, ... when taken.
             */
            'name' => ['required_without:project_id', 'nullable', 'string', 'max:'.ManagedDatabase::MAX_NAME_LENGTH, $this->uniqueName()],
            /**
             * Region of the database node, for example `jkt`. `null` (the default) uses the workspace's default region,
             * or any region without one; with `project_id`, the project's region first. Projects attach only to
             * databases of their own region.
             */
            'region' => ['sometimes', 'nullable', 'string', 'max:'.RegionSlug::MAX_LENGTH, new RegionSlug],
            /**
             * A project of this workspace the caller may change, which has no database yet: the new database is
             * attached to it at once, while it is still `provisioning`. Another workspace's project answers `404`.
             */
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            /**
             * Deploy the project as the caller once the database is ready, through the normal deployment path with
             * every limit. Defaults to `false`; `true` only with `project_id`.
             */
            'deploy_when_ready' => ['sometimes', 'boolean', Rule::prohibitedIf(fn (): bool => $this->input('project_id') === null && $this->boolean('deploy_when_ready'))],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    /** The validated body; a `project_id` of another workspace, or of no project, ends in `404`. */
    public function toData(): CreateDatabaseData
    {
        $name = $this->validated('name');

        return new CreateDatabaseData(
            name: is_string($name) ? trim($name) : null,
            region: $this->region(),
            project: $this->project(),
            deployWhenReady: $this->boolean('deploy_when_ready'),
        );
    }

    private function project(): ?Project
    {
        $projectId = $this->validated('project_id');

        if (! is_string($projectId)) {
            return null;
        }

        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return Project::query()->whereKey($projectId)->where('workspace_id', $workspace->id)->first() ?? abort(404);
    }

    public function region(): ?string
    {
        $region = $this->validated('region');

        return is_string($region) ? $region : null;
    }

    /** Unique among the workspace's live databases, apart from the one being renamed. */
    protected function uniqueName(): mixed
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');
        $database = $this->route('database');

        return Rule::unique('databases', 'name')
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->ignore($database instanceof ManagedDatabase ? $database->id : null);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
