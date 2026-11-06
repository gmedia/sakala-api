<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class RequestEmailChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'email' => ['required', 'string', 'email', 'max:255', Rule::notIn([$user->email])],
            // Without a password there is nothing to confirm with: such an
            // account sets one first (`has_password` tells the console).
            'current_password' => ['required', 'string', 'current_password:web'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($email = $this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    public function newEmail(): string
    {
        /** @var string $email */
        $email = $this->validated('email');

        return $email;
    }
}
