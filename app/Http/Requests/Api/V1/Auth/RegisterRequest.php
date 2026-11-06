<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Data\Auth\RegisterData;
use App\Http\Requests\Concerns\ValidatesLegalAcceptance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    use ValidatesLegalAcceptance;

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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            ...$this->legalAcceptanceRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($email = $this->input('email'))) {
            $normalized['email'] = Str::lower(trim($email));
        }

        if (is_string($name = $this->input('name'))) {
            $normalized['name'] = Str::squish($name);
        }

        $this->merge($normalized);
    }

    public function toData(): RegisterData
    {
        /** @var string $name */
        $name = $this->validated('name');

        /** @var string $email */
        $email = $this->validated('email');

        /** @var string $password */
        $password = $this->validated('password');

        return new RegisterData(
            name: $name,
            email: $email,
            password: $password,
        );
    }
}
