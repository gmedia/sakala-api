<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Deployment\DeploymentPaginateData;
use Illuminate\Foundation\Http\FormRequest;

/** Same parameters as the member endpoint for deployment events. */
final class AdminDeploymentEventRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'between:1,10'],
        ];
    }

    public function toData(): DeploymentPaginateData
    {
        return new DeploymentPaginateData(
            perPage: $this->integer('per_page', 6),
            cursor: $this->input('cursor'),
        );
    }
}
