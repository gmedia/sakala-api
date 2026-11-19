<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\GitHub\SkippedPushData;
use App\Data\Project\GetSkippedPushCollectionData;
use App\Models\AuditEvent;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Pushes a project skipped because they concerned other projects of its monorepo, newest first. */
final class GetSkippedPushCollectionAction
{
    /**
     * Served by the `(subject_type, subject_id, created_at)` index of `audit_events`.
     *
     * @return LengthAwarePaginator<int, AuditEvent>
     */
    public function handle(Project $project, GetSkippedPushCollectionData $data): LengthAwarePaginator
    {
        return AuditEvent::query()
            ->where('subject_type', Project::class)
            ->where('subject_id', $project->id)
            ->where('action', SkippedPushData::AUDIT_ACTION)
            ->when($data->since !== null, fn ($query) => $query->where('created_at', '>=', $data->since))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $data->perPage, page: $data->page)
            ->withQueryString();
    }
}
