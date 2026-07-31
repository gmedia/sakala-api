<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Data\Project\GetProjectCollectionData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,6'],
            'search' => ['sometimes', 'string', 'max:255'],
            'filter' => ['sometimes', 'in:7_days,30_days,all'],
        ];
    }

    public function toData(): GetProjectCollectionData
    {
        $search = $this->string('search')->toString();
        $filter = $this->string('filter')->toString();

        return new GetProjectCollectionData(
            page: $this->integer('page', 1),
            perPage: $this->integer('per_page', 6),
            search: $search,
            filter: $filter,
        );
    }
}
