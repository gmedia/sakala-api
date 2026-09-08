<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Profile;

use App\Data\Profile\ProfileData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'max:50', 'regex:/^[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*$/', Rule::unique('users', 'username')->ignore($this->user()->id)],
            'avatar' => ['sometimes', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ];
    }

    public function toData(): ProfileData
    {
        return new ProfileData(
            name: $this->has('name') ? $this->string('name')->toString() : null,
            username: $this->has('username') ? $this->string('username')->toString() : null,
            avatar: $this->file('avatar'),
        );
    }
}
