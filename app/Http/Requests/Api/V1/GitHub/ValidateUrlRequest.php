<?php

namespace App\Http\Requests\Api\V1\GitHub;

use App\Data\GitHub\ValidateGithubRepositoryData;
use App\Rules\GithubRepositoryUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ValidateUrlRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'repository_url' => ['required', 'string', 'max:255', 'url', new GithubRepositoryUrl()],
        ];
    }

    public function toData(): ValidateGithubRepositoryData
    {
        return new ValidateGithubRepositoryData(
            repositoryUrl: $this->validated('repository_url'),
        );
    }
}