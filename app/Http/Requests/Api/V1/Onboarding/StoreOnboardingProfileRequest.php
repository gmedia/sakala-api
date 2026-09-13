<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Onboarding;

use App\Data\Onboarding\StoreOnboardingProfileData;
use App\Enums\OnboardingProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOnboardingProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required_without:skip', 'prohibits:skip', 'string', 'max:255'],
            'role' => ['required_without:skip', 'prohibits:skip', Rule::enum(OnboardingProfile::class)],
            'skip' => ['sometimes', 'boolean', 'accepted', 'prohibits:name,role'],
        ];
    }

    public function toData(): StoreOnboardingProfileData
    {
        $skip = $this->boolean('skip');

        /** @var string|null $name */
        $name = $this->validated('name');

        /** @var string|null $rawRole */
        $rawRole = $this->validated('role');

        $role = $rawRole !== null
            ? OnboardingProfile::tryFrom($rawRole)
            : null;

        return new StoreOnboardingProfileData(
            name: $name,
            role: $role,
            skip: $skip,
        );
    }
}
