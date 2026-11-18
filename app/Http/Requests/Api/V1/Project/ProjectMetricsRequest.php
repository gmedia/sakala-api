<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Enums\ProjectMetricsRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reading metrics takes the same rights as viewing the project.
 */
final class ProjectMetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Defaults to 1h.
            'range' => ['sometimes', 'string', Rule::enum(ProjectMetricsRange::class)],
            // `1` adds `series.per_replica`.
            'per_replica' => ['sometimes', 'boolean'],
        ];
    }

    public function range(): ProjectMetricsRange
    {
        return $this->enum('range', ProjectMetricsRange::class) ?? ProjectMetricsRange::DEFAULT;
    }

    public function perReplica(): bool
    {
        return $this->boolean('per_replica');
    }
}
