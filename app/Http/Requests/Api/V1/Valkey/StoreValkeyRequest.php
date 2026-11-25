<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Valkey;

use App\Data\Valkey\CreateValkeyData;
use App\Enums\ValkeyMode;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Models\Workspace;
use App\Rules\RegionSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreValkeyRequest extends FormRequest
{
    /** Owners and members of the workspace, and admins; checked before the body is validated. */
    public function authorize(): bool
    {
        return $this->user()?->can('create', [ManagedValkey::class, $this->route('workspace')]) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            /**
             * Name people see, unique among the workspace's live stores. Optional with `project_id`: the project's
             * name, followed by ` 2`, ` 3`, ... when taken.
             */
            'name' => ['required_without:project_id', 'nullable', 'string', 'max:'.ManagedValkey::MAX_NAME_LENGTH, $this->uniqueName()],
            /**
             * `cache`: no persistence, the least recently used keys are evicted when full, and a restart empties
             * it. `persistent`: append-only file every second, writes are refused when full, backed up nightly;
             * for queues and sessions. Fixed once created.
             */
            'mode' => ['required', 'string', Rule::enum(ValkeyMode::class)],
            /**
             * `maxmemory` in MB: 32, 64, or 128. `null` (the default) is 64. Reserved from the workspace's Valkey
             * memory at once.
             */
            'maxmemory_mb' => ['sometimes', 'nullable', 'integer', Rule::in(ManagedValkey::SIZES_MB)],
            /**
             * Sakala region of the Valkey node, for example `jkt`. `null` (the default) uses the project's region
             * with `project_id`, then the workspace's default region, then any region. A store is reachable only
             * from projects on its own node.
             */
            'region' => ['sometimes', 'nullable', 'string', 'max:'.RegionSlug::MAX_LENGTH, new RegionSlug],
            /**
             * A project of this workspace the caller may change, which has no store yet: the new store goes to
             * the node the project runs on and is attached to it at once, while it is still `provisioning`.
             * Another workspace's project answers `404`.
             */
            'project_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /** The validated body; a `project_id` of another workspace, or of no project, ends in `404`. */
    public function toData(): CreateValkeyData
    {
        $name = $this->validated('name');
        $region = $this->validated('region');
        $size = $this->validated('maxmemory_mb');

        return new CreateValkeyData(
            name: is_string($name) ? trim($name) : null,
            mode: ValkeyMode::from((string) $this->validated('mode')),
            maxmemoryMb: is_int($size) || is_string($size) ? (int) $size : ManagedValkey::DEFAULT_SIZE_MB,
            region: is_string($region) ? $region : null,
            project: $this->project(),
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

    /** Unique among the workspace's live stores, apart from the one being renamed. */
    protected function uniqueName(): mixed
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');
        $valkey = $this->route('valkey');

        return Rule::unique('valkeys', 'name')
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->ignore($valkey instanceof ManagedValkey ? $valkey->id : null);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
