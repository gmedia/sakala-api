<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Deployment\DeploymentPaginateData;
use App\Http\Requests\Api\V1\Deployment\IndexDeploymentLogRequest;
use Illuminate\Foundation\Http\FormRequest;

/** Same parameters as the member endpoint for deployment logs. */
final class AdminDeploymentLogRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.IndexDeploymentLogRequest::MAX_PER_PAGE],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
        ];
    }

    public function toData(): DeploymentPaginateData
    {
        return new DeploymentPaginateData(
            perPage: $this->integer('per_page', 6),
            cursor: $this->input('cursor'),
            newestFirst: $this->input('order') === 'desc',
        );
    }
}
