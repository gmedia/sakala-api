<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Database;

use App\Enums\DatabaseExtension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InstallDatabaseExtensionRequest extends FormRequest
{
    /** Owners and members of the workspace, and admins; checked before the body is validated. */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('database')) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            /** One of `pgcrypto`, `citext`, `pg_trgm`, `unaccent`, `hstore`, `vector` (pgvector). */
            'extension' => ['required', 'string', Rule::enum(DatabaseExtension::class)],
        ];
    }

    public function extension(): DatabaseExtension
    {
        return DatabaseExtension::from((string) $this->validated('extension'));
    }
}
