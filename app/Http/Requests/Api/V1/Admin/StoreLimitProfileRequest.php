<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\LimitProfileData;
use App\Http\Requests\Concerns\RequiresAdminReason;
use App\Models\LimitProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLimitProfileRequest extends FormRequest
{
    use RequiresAdminReason;

    public const FIELDS = ['name', 'max_projects', 'max_active_containers', 'max_active_deployments', 'max_memory_mb', 'max_cpu_millis', 'max_builds_per_hour', 'idle_sleep_minutes'];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->reasonRules(),
            'name' => ['required', 'string', 'max:100', Rule::unique(LimitProfile::class, 'name')],
            'max_projects' => ['required', 'integer', 'between:1,1000'],
            /** Projects that serve or are being deployed at once. */
            'max_active_containers' => ['required', 'integer', 'between:1,1000'],
            'max_active_deployments' => ['required', 'integer', 'between:1,100'],
            /** Per project, in MB. */
            'max_memory_mb' => ['required', 'integer', 'between:64,65536'],
            /** Per project, in thousandths of a CPU. */
            'max_cpu_millis' => ['required', 'integer', 'between:100,64000'],
            /** Reserved for the build rate limit; stored but not enforced yet. */
            'max_builds_per_hour' => ['sometimes', 'nullable', 'integer', 'between:1,1000'],
            /**
             * Minutes without a request before a project sleeps, up to a week. `0` never puts the workspace's
             * projects to sleep; `null` (the default) follows `SAKALA_PROJECT_IDLE_SLEEP_MINUTES`.
             */
            'idle_sleep_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,'.LimitProfile::MAX_IDLE_SLEEP_MINUTES],
        ];
    }

    public function toData(): LimitProfileData
    {
        /** @var array<string, int|string|null> $attributes */
        $attributes = $this->safe()->only(self::FIELDS);

        return new LimitProfileData($attributes, $this->reason());
    }
}
