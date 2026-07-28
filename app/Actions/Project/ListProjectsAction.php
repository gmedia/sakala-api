<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Models\Project;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListProjectsAction
{
    /**
     * Handle the listing of projects for a user.
     *
     * @return LengthAwarePaginator<array-key, Project>
     */
    public function handle(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $query = Project::forUser($user)->orderBy('created_at', 'desc');

        return $query->paginate($perPage);
    }
}
