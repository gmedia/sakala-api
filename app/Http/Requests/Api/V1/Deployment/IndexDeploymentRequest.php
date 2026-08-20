<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Deployment;

use App\Data\Deployment\GetDeploymentCollectionData;
use Illuminate\Foundation\Http\FormRequest;

final class IndexDeploymentRequest extends FormRequest
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
            'per_page' => ['sometimes', 'integer', 'between:1,6'],
            'search' => ['sometimes', 'string', 'max:255'],
            'filter' => ['sometimes', 'in:7_days,30_days,all'],
        ];
    }

    public function toData(): GetDeploymentCollectionData
    {
        $search = $this->string('search')->toString();
        $filter = $this->string('filter')->toString();

        return new GetDeploymentCollectionData(
            page: $this->integer('page', 1),
            perPage: $this->integer('per_page', 6),
            search: $search,
            filter: $filter,
        );
    }
}
