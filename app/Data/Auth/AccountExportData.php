<?php

declare(strict_types=1);

namespace App\Data\Auth;

use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

final readonly class AccountExportData
{
    /**
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Deployment>  $deployments
     * @param  Collection<int, EnvironmentVariable>  $environmentVariables
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @param  Collection<int, Feedback>  $feedback
     * @param  list<string>  $truncated
     */
    public function __construct(
        public CarbonImmutable $exportedAt,
        public User $user,
        public Collection $projects,
        public Collection $deployments,
        public Collection $environmentVariables,
        public Collection $notifications,
        public Collection $feedback,
        public int $maxItems,
        public array $truncated,
    ) {}
}
