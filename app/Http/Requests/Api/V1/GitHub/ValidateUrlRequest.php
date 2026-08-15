<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\GitHub;

use App\Data\GitHub\GithubRepositoryUrlData;
use App\Rules\GithubRepositoryUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ValidateUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'repository_url' => ['bail', 'required', 'string', 'url', 'max:255', new GithubRepositoryUrl],
        ];
    }

    public function toData(): GithubRepositoryUrlData
    {
        return new GithubRepositoryUrlData(
            repositoryUrl: $this->validated('repository_url'),
        );
    }
}
