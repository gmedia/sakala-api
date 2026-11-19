<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Database;

use App\Models\ManagedDatabase;

final class UpdateDatabaseRequest extends StoreDatabaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('database')) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            /** New name people see, unique among the workspace's databases. */
            'name' => ['required', 'string', 'max:'.ManagedDatabase::MAX_NAME_LENGTH, $this->uniqueName()],
        ];
    }
}
