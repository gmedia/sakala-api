<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Requests\Concerns\RequiresAdminReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserRoleRequest extends FormRequest
{
    use RequiresAdminReason;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->reasonRules(),
            'role' => ['required', 'string', Rule::enum(UserRole::class)],
        ];
    }

    public function role(): UserRole
    {
        return UserRole::from($this->string('role')->toString());
    }
}
