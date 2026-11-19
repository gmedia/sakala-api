<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Database;

use Illuminate\Foundation\Http\FormRequest;

final class RotateDatabasePasswordRequest extends FormRequest
{
    /** Owners and members of the workspace, and admins; checked before the body is validated. */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('database')) === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            /**
             * Redeploy every attached project that has a live deployment as soon as the node reports the new
             * password, as whoever asked for the rotation. Defaults to `false`.
             */
            'redeploy_attached' => ['sometimes', 'boolean'],
        ];
    }

    public function redeployAttached(): bool
    {
        return $this->boolean('redeploy_attached');
    }
}
