<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Schedule;

use App\Data\Deployment\DeploymentPaginateData;
use Illuminate\Foundation\Http\FormRequest;

/** A page of a schedule's runs, newest first, or of one run's log lines. */
final class IndexScheduleRunRequest extends FormRequest
{
    public const MAX_PER_PAGE = 50;

    public const DEFAULT_PER_PAGE = 20;

    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('project')) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
        ];
    }

    public function toData(): DeploymentPaginateData
    {
        return new DeploymentPaginateData(
            perPage: $this->integer('per_page', self::DEFAULT_PER_PAGE),
            cursor: $this->input('cursor'),
        );
    }
}
