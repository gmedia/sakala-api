<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Concerns\RequiresAdminReason;
use App\Models\LimitProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignLimitProfileRequest extends FormRequest
{
    use RequiresAdminReason;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->reasonRules(),
            /** The profile to attach, or `null` to return to the default of the workspace type. */
            'limit_profile_id' => ['present', 'nullable', 'uuid', Rule::exists(LimitProfile::class, 'id')],
        ];
    }

    public function limitProfile(): ?LimitProfile
    {
        if (! $this->filled('limit_profile_id')) {
            return null;
        }

        return LimitProfile::query()->whereKey($this->string('limit_profile_id')->toString())->firstOrFail();
    }
}
