<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Bucket;

use App\Data\Bucket\CreateBucketData;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Models\Workspace;
use App\Rules\RegionSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBucketRequest extends FormRequest
{
    /** Owners and members of the workspace, and admins; checked before the body is validated. */
    public function authorize(): bool
    {
        return $this->user()?->can('create', [ManagedBucket::class, $this->route('workspace')]) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            /**
             * Name people see, unique among the workspace's live buckets. The bucket name on the storage node is
             * random. Optional with `project_id`: the project's name, followed by ` 2`, ` 3`, ... when taken.
             */
            'name' => ['required_without:project_id', 'nullable', 'string', 'max:'.ManagedBucket::MAX_NAME_LENGTH, $this->uniqueName()],
            /**
             * Sakala region of the storage node, for example `jkt`. `null` (the default) uses the project's region
             * with `project_id`, then the workspace's default region, then any region. A bucket of any region can be
             * attached to any project of the workspace: the endpoint is public.
             */
            'region' => ['sometimes', 'nullable', 'string', 'max:'.RegionSlug::MAX_LENGTH, new RegionSlug],
            /**
             * A project of this workspace the caller may change, which has no bucket yet: the new bucket is attached
             * to it at once, while it is still `provisioning`. Another workspace's project answers `404`.
             */
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            /**
             * Hard quota in MB; uploads past it are refused. Reserved from the workspace's bucket storage at once.
             * `null` (the default) uses `buckets.default_quota_mb` from `GET app/pilot-limits`, capped at what the
             * workspace has left.
             */
            'quota_mb' => ['sometimes', 'nullable', 'integer', 'between:'.ManagedBucket::MIN_QUOTA_MB.','.ManagedBucket::MAX_QUOTA_MB],
            /** Not accepted in this version: a bucket never deploys its project by itself. Sending it answers `422`. */
            'deploy_when_ready' => ['prohibited'],
        ];
    }

    /** The validated body; a `project_id` of another workspace, or of no project, ends in `404`. */
    public function toData(): CreateBucketData
    {
        $name = $this->validated('name');
        $region = $this->validated('region');
        $quota = $this->validated('quota_mb');

        return new CreateBucketData(
            name: is_string($name) ? trim($name) : null,
            region: is_string($region) ? $region : null,
            quotaMb: is_int($quota) || is_string($quota) ? (int) $quota : null,
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

    /** Unique among the workspace's live buckets, apart from the one being renamed. */
    protected function uniqueName(): mixed
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');
        $bucket = $this->route('bucket');

        return Rule::unique('buckets', 'name')
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->ignore($bucket instanceof ManagedBucket ? $bucket->id : null);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
