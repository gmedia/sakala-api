<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Deployment;

use App\Data\Deployment\DeploymentPaginateData;
use Illuminate\Foundation\Http\FormRequest;

final class PaginateDeploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,10'],
        ];
    }

    public function toData(): DeploymentPaginateData
    {
        return new DeploymentPaginateData(
            page: $this->integer('page', 1),
            perPage: $this->integer('per_page', 6),
        );
    }
}
