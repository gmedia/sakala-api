<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Data\Auth\LoginData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function toData(): LoginData
    {
        /** @var string $email */
        $email = $this->validated('email');

        /** @var string $password */
        $password = $this->validated('password');

        return new LoginData(
            email: $email,
            password: $password,
        );
    }
}
