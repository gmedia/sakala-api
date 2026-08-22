<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Deployment;

use App\Data\Deployment\CreateDeploymentData;
use App\Data\Runtime\RuntimeResourceLimitsData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreDeploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('deploy', $this->route('project'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch' => ['required', 'string', 'max:255'],
            'resources' => ['nullable', 'array'],
            'resources.memory_mb' => ['nullable', 'integer', 'min:1'],
            'resources.cpu_millis' => ['nullable', 'integer', 'min:1'],
            'resources.pids_limit' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function getIdempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && ! empty($key) ? $key : null;
    }

    public function toData(): CreateDeploymentData
    {
        $branch = (string) $this->validated('branch');
        $idempotencyKey = $this->getIdempotencyKey();
        $resources = $this->validated('resources');
        $requestedResources = is_array($resources)
            ? RuntimeResourceLimitsData::fromArray($resources)
            : null;

        return new CreateDeploymentData(
            branch: $branch,
            idempotencyKey: $idempotencyKey,
            requested_resources: $requestedResources,
        );
    }
}
