<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Schedule;

/**
 * A partial change of a schedule: every field is optional, with the rules of
 * StoreProjectScheduleRequest. Enabling a schedule the control plane paused
 * clears the pause and its failure count.
 */
final class UpdateProjectScheduleRequest extends StoreProjectScheduleRequest
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
}
