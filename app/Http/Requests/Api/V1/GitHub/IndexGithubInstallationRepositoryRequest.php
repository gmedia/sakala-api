<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\GitHub;

use Illuminate\Foundation\Http\FormRequest;

final class IndexGithubInstallationRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }
}
