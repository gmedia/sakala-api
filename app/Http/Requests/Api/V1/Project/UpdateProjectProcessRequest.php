<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Models\ProjectProcess;

/**
 * A partial change of a worker definition: every field is optional, with
 * the rules of StoreProjectProcessRequest.
 */
final class UpdateProjectProcessRequest extends StoreProjectProcessRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();

        foreach (self::FIELDS as $field) {
            $rules[$field] = array_map(
                static fn (mixed $rule): mixed => $rule === 'required' ? 'sometimes' : $rule,
                (array) $rules[$field],
            );
        }

        return $rules;
    }

    protected function ignoredProcessId(): ?string
    {
        $process = $this->route('process');

        return $process instanceof ProjectProcess ? $process->id : null;
    }
}
