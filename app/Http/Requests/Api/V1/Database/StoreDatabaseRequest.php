<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Database;

use App\Models\ManagedDatabase;
use App\Models\Workspace;
use App\Rules\RegionSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDatabaseRequest extends FormRequest
{
    /** Owners and members of the workspace, and admins; checked before the body is validated. */
    public function authorize(): bool
    {
        return $this->user()?->can('create', [ManagedDatabase::class, $this->route('workspace')]) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            /** Name people see, unique among the workspace's databases. The names on the database node are random. */
            'name' => ['required', 'string', 'max:'.ManagedDatabase::MAX_NAME_LENGTH, $this->uniqueName()],
            /**
             * Region of the database node, for example `jkt`. `null` (the default) uses the workspace's default region,
             * or any region without one. Projects attach only to databases of their own region.
             */
            'region' => ['sometimes', 'nullable', 'string', 'max:'.RegionSlug::MAX_LENGTH, new RegionSlug],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function region(): ?string
    {
        $region = $this->validated('region');

        return is_string($region) ? $region : null;
    }

    /** Unique among the workspace's live databases, apart from the one being renamed. */
    protected function uniqueName(): mixed
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');
        $database = $this->route('database');

        return Rule::unique('databases', 'name')
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->ignore($database instanceof ManagedDatabase ? $database->id : null);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
