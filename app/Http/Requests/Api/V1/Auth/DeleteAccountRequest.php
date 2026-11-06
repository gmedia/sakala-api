<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Exceptions\Auth\ReauthenticationRequiredException;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class DeleteAccountRequest extends FormRequest
{
    /** Set when an OAuth login succeeds, so it proves this session and not just this account. */
    public const OAUTH_AUTHENTICATED_AT = 'auth.oauth_authenticated_at';

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
            // Typed by hand, so it cannot be done by accident.
            'email' => ['required', 'string', Rule::in([$user->email])],
            'current_password' => [
                Rule::excludeIf($user->password === null),
                'required',
                'string',
                'current_password:web',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($email = $this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    protected function passedValidation(): void
    {
        /** @var User $user */
        $user = $this->user();

        if ($user->password !== null) {
            return;
        }

        $authenticatedAt = $this->session()->get(self::OAUTH_AUTHENTICATED_AT);
        $window = 60 * (int) config('sakala.account.oauth_reauthentication_minutes');

        if (! is_int($authenticatedAt) || now()->getTimestamp() - $authenticatedAt > $window) {
            throw new ReauthenticationRequiredException;
        }
    }
}
