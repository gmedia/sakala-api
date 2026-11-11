<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\FailedDeploymentFilterData;
use App\Enums\AdminTimeRange;
use App\Enums\DeploymentFailureCategory;
use App\Http\Requests\Concerns\PaginatesAdminList;
use App\Http\Requests\Concerns\ResolvesAdminTimeRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexFailedDeploymentRequest extends FormRequest
{
    use PaginatesAdminList;
    use ResolvesAdminTimeRange;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            ...$this->timeRangeRules(array_column(AdminTimeRange::cases(), 'value')),
            /** Matches the project's name or slug, case-insensitively. */
            'search' => ['sometimes', 'string', 'max:255'],
            /** A `DeploymentFailureCategory`. `unknown` includes failures recorded without a code. */
            'category' => ['sometimes', 'string', Rule::enum(DeploymentFailureCategory::class)],
            /** An exact failure code, for example `runtime_build_failed`. */
            'code' => ['sometimes', 'string', 'max:100'],
        ];
    }

    public function toData(): FailedDeploymentFilterData
    {
        return new FailedDeploymentFilterData(
            page: $this->page(),
            perPage: $this->perPage(),
            range: $this->timeRange(AdminTimeRange::Last30Days),
            search: $this->search(),
            category: $this->filled('category') ? DeploymentFailureCategory::from($this->string('category')->toString()) : null,
            code: $this->filled('code') ? $this->string('code')->toString() : null,
        );
    }
}
