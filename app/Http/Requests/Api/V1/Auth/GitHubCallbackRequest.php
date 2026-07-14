<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

final class GitHubCallbackRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'state' => ['required', 'string', 'size:64'], // state must match CSRF token stored in session
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $storedState = Session::get('github_oauth_state');
            $requestState = $this->input('state');

            if (! $storedState || ! hash_equals($storedState, $requestState)) {
                $validator->errors()->add('state', 'Invalid or expired OAuth state.');
            }
        });
    }

    public function authorize(): bool
    {
        return true; // Public endpoint for OAuth callback
    }

    /**
     * Override failed validation to return JSON response (API).
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
