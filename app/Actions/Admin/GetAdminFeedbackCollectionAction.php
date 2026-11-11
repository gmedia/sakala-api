<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AdminFeedbackFilterData;
use App\Models\Feedback;
use App\Support\Admin\AdminSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class GetAdminFeedbackCollectionAction
{
    /** @return LengthAwarePaginator<int, Feedback> */
    public function handle(AdminFeedbackFilterData $data): LengthAwarePaginator
    {
        $query = Feedback::query()->with([
            'user:id,name,username,email',
            'project' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'slug', 'deleted_at']),
        ]);

        AdminSearch::apply($query, ['message'], $data->search);

        return $query
            ->when($data->status !== null, fn ($query) => $query->where('status', $data->status))
            ->when($data->category !== null, fn ($query) => $query->where('category', $data->category))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $data->perPage, page: $data->page)
            ->withQueryString();
    }
}
