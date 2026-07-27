<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Onboarding;

use App\Data\Onboarding\StoreOnboardingSourceData;
use App\Enums\OnboardingSource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOnboardingSourceRequest extends FormRequest
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
            'source' => ['required_without:skip', 'nullable', Rule::enum(OnboardingSource::class)],
            'skip' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): StoreOnboardingSourceData
    {
        /** @var string|null $rawSource */
        $rawSource = $this->validated('source');
        $skip = $this->boolean('skip') || ($rawSource === null && $this->has('source'));

        $source = $rawSource !== null ? OnboardingSource::tryFrom($rawSource) : null;

        return new StoreOnboardingSourceData(
            source: $source,
            skip: $skip,
        );
    }
}
