<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Deployment;

use App\Data\Deployment\CreateDeploymentData;
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
        ];
    }

    public function getIdempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');
        return is_string($key) && !empty($key) ? $key : null;
    }

    public function toData(): CreateDeploymentData
    {
        $branch = $this->validated('branch');
        $idempotencyKey = $this->getIdempotencyKey();

        return new CreateDeploymentData(
            branch: $branch,
            idempotencyKey: $idempotencyKey,
        );
    }
}