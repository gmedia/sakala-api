<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\CustomDomain;

use Illuminate\Foundation\Http\FormRequest;

/** An admin disabling a custom domain (ADR-024); the reason is shown to the project's people. */
final class DisableCustomDomainRequest extends FormRequest
{
    /** Longest reason, as the column allows. */
    public const MAX_REASON_LENGTH = 500;

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            /** Why, in words the project's owners understand; it is in their notification and email. */
            'reason' => ['required', 'string', 'min:3', 'max:'.self::MAX_REASON_LENGTH],
        ];
    }

    public function reason(): string
    {
        return trim($this->string('reason')->toString());
    }
}
