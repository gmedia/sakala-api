<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\LimitProfile;
use Illuminate\Validation\Rule;

final class UpdateLimitProfileRequest extends StoreLimitProfileRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var LimitProfile $profile */
        $profile = $this->route('limitProfile');
        $rules = parent::rules();

        foreach (self::FIELDS as $field) {
            $rules[$field] = array_map(
                static fn (mixed $rule): mixed => $rule === 'required' ? 'sometimes' : $rule,
                $rules[$field],
            );
        }

        $rules['name'] = ['sometimes', 'string', 'max:100', Rule::unique(LimitProfile::class, 'name')->ignore($profile->id)];

        return $rules;
    }
}
