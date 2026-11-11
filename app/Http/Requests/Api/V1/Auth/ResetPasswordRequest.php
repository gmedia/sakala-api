<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Data\Auth\PasswordResetData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($email = $this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    public function toData(): PasswordResetData
    {
        /** @var array{email: string, token: string, password: string} $validated */
        $validated = $this->validated();

        return new PasswordResetData(
            email: $validated['email'],
            token: $validated['token'],
            password: $validated['password'],
        );
    }
}
