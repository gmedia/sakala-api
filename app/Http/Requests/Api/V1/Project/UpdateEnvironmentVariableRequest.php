<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateEnvironmentVariableRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()->can('update', $project);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'value' => ['sometimes', 'required', 'string'],
            'is_secret' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function newValue(): ?string
    {
        /** @var string|null $value */
        $value = $this->validated('value');

        return $value;
    }

    public function newIsSecret(): ?bool
    {
        return $this->has('is_secret') ? $this->boolean('is_secret') : null;
    }
}
