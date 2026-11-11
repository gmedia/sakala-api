<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AuditEventFilterData;
use App\Models\AuditEvent;
use App\Support\Admin\AuditActorLoader;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Audit events newest first. Each filter matches the leading columns of an
 * existing index: `(actor_type, actor_id, created_at)`, `(subject_type,
 * subject_id, created_at)`, `(action, created_at)`, or `created_at` alone.
 */
final class GetAuditEventCollectionAction
{
    /** @return LengthAwarePaginator<int, AuditEvent> */
    public function handle(AuditEventFilterData $data): LengthAwarePaginator
    {
        $events = AuditEvent::query()
            ->when($data->actorType !== null, fn (Builder $query) => $query->where('actor_type', $data->actorType))
            ->when($data->actorId !== null, fn (Builder $query) => $query->where('actor_id', $data->actorId))
            ->when($data->subjectType !== null, fn (Builder $query) => $query->where('subject_type', $data->subjectType))
            ->when($data->subjectId !== null, fn (Builder $query) => $query->where('subject_id', $data->subjectId))
            ->when($data->action !== null, fn (Builder $query) => $query->where('action', $data->action))
            ->when($data->range->from !== null, fn (Builder $query) => $query->where('created_at', '>=', $data->range->from))
            ->where('created_at', '<', $data->range->to)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $data->perPage, page: $data->page)
            ->withQueryString();

        AuditActorLoader::load($events->items());

        return $events;
    }
}
