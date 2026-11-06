<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Deployment;

use App\Data\Deployment\DeploymentPaginateData;
use Illuminate\Foundation\Http\FormRequest;

final class IndexDeploymentLogRequest extends FormRequest
{
    /**
     * Worst case with SAKALA_LOG_MAX_LINE_LENGTH at its 4096 byte default is about
     * 800 KB per response.
     */
    public const MAX_PER_PAGE = 200;

    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
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
