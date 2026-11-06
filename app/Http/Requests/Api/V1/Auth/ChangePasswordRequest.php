<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class ChangePasswordRequest extends FormRequest
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
            // An OAuth-only account has nothing to confirm with yet.
            'current_password' => [
                Rule::excludeIf($user->password === null),
                'required',
                'string',
                'current_password:web',
            ],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    public function newPassword(): string
    {
        /** @var string $password */
        $password = $this->validated('password');

        return $password;
    }
}
