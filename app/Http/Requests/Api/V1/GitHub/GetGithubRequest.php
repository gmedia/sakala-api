<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\GitHub;

use App\Data\GitHub\GetGithubRepositoryData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GetGithubRequest extends FormRequest
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
            'per_page' => ['sometimes', 'integer', 'between:1,5'],
            'search' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function toData(): GetGithubRepositoryData
    {
        $search = $this->string('search')->toString();

        return new GetGithubRepositoryData(
            page: $this->integer('page', 1),
            perPage: $this->integer('per_page', 5),
            search: $search,
        );
    }
}