<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\GetProjectCollectionData;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetProjectCollectionAction
{
    /**
     * @return LengthAwarePaginator<int, Project>
     */
    public function handle(
        User $user,
        GetProjectCollectionData $data
    ): LengthAwarePaginator {
        $query = Project::query()
            ->whereBelongsTo($user)
            ->when(
                filled($data->search),
                fn ($query) => $query->where('name', 'ILIKE', "%{$data->search}%")
            );

        match ($data->filter) {
            '7_days' => $query->where('created_at', '>=', now()->subDays(7)),
            '30_days' => $query->where('created_at', '>=', now()->subDays(30)),
            default => null,
        };

        return $query
            ->latest('created_at')
            ->paginate(
                perPage: $data->perPage,
                page: $data->page
            );
    }
}
