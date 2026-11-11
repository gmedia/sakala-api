<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Workspace;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class RespondWorkspaceInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // From the email link. Without it, the invitation must be addressed
            // to the caller's verified email.
            'token' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }

    public function token(): ?string
    {
        $token = $this->validated('token');

        return is_string($token) ? $token : null;
    }
}
