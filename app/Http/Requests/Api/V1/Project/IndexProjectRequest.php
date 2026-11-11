<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Data\Project\GetProjectCollectionData;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class IndexProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if (! $this->user()->can('viewAny', Project::class)) {
            return false;
        }

        $workspaceId = $this->query('workspace_id');
        $workspace = is_string($workspaceId) && Str::isUuid($workspaceId)
            ? Workspace::query()->find($workspaceId)
            : null;

        return $workspace === null || $this->user()->can('view', $workspace);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,6'],
            'search' => ['sometimes', 'string', 'max:255'],
            'filter' => ['sometimes', 'in:7_days,30_days,90_days,all'],
            // Optional: without it the list covers every workspace the user belongs to.
            'workspace_id' => ['sometimes', 'uuid', Rule::exists('workspaces', 'id')],
        ];
    }

    public function toData(): GetProjectCollectionData
    {
        $search = $this->string('search')->toString();
        $filter = $this->string('filter')->toString();

        return new GetProjectCollectionData(
            page: $this->integer('page', 1),
            perPage: $this->integer('per_page', 6),
            search: $search,
            filter: $filter,
            workspaceId: $this->validated('workspace_id'),
        );
    }
}
