<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AdminUserFilterData;
use App\Models\User;
use App\Support\Admin\AdminSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class GetAdminUserCollectionAction
{
    /** @return LengthAwarePaginator<int, User> */
    public function handle(AdminUserFilterData $data): LengthAwarePaginator
    {
        $query = User::query()->withCount('projects');

        AdminSearch::apply($query, ['name', 'username', 'email'], $data->search);

        return $query
            ->when($data->role !== null, fn ($query) => $query->where('role', $data->role))
            ->when($data->verified === true, fn ($query) => $query->whereNotNull('email_verified_at'))
            ->when($data->verified === false, fn ($query) => $query->whereNull('email_verified_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $data->perPage, page: $data->page)
            ->withQueryString();
    }
}
