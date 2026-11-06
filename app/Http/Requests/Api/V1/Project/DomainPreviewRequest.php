<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

final class DomainPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Project::class);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    public function projectName(): string
    {
        /** @var string $name */
        $name = $this->validated('name');

        return $name;
    }
}
