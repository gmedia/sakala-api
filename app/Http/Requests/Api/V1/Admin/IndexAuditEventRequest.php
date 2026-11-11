<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\AuditEventFilterData;
use App\Enums\AdminTimeRange;
use App\Http\Requests\Concerns\PaginatesAdminList;
use App\Http\Requests\Concerns\ResolvesAdminTimeRange;
use App\Support\Admin\AuditMorphAlias;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexAuditEventRequest extends FormRequest
{
    use PaginatesAdminList;
    use ResolvesAdminTimeRange;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            ...$this->timeRangeRules(array_column(AdminTimeRange::cases(), 'value')),
            /** `user`, `agent_node`, `system`, or `github_app`. */
            'actor_type' => ['sometimes', 'string', Rule::in(AuditMorphAlias::aliases())],
            /** With `actor_type`, for example a user ID. */
            'actor_id' => ['sometimes', 'string', 'max:64', 'required_with_all:actor_type'],
            /** An exact action, for example `project.suspend_requested`. */
            'action' => ['sometimes', 'string', 'max:100'],
            'subject_type' => ['sometimes', 'string', Rule::in(AuditMorphAlias::aliases())],
            /** With `subject_type`. */
            'subject_id' => ['sometimes', 'string', 'max:64'],
        ];
    }

    public function toData(): AuditEventFilterData
    {
        return new AuditEventFilterData(
            page: $this->page(),
            perPage: $this->perPage(),
            range: $this->timeRange(AdminTimeRange::All),
            actorType: $this->filled('actor_type') ? AuditMorphAlias::toStored($this->string('actor_type')->toString()) : null,
            actorId: $this->filled('actor_id') ? $this->string('actor_id')->toString() : null,
            action: $this->filled('action') ? $this->string('action')->toString() : null,
            subjectType: $this->filled('subject_type') ? AuditMorphAlias::toStored($this->string('subject_type')->toString()) : null,
            subjectId: $this->filled('subject_id') ? $this->string('subject_id')->toString() : null,
        );
    }
}
