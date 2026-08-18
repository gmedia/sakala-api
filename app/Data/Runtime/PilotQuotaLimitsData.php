<?php

declare(strict_types=1);

namespace App\Data\Runtime;

final readonly class PilotQuotaLimitsData
{
    public function __construct(
        public int $max_projects_per_user,
        public int $max_active_deployments_per_user,
        public int $max_active_deployments_per_project,
        public int $current_projects_count,
        public int $current_active_deployments_count,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'max_projects_per_user' => $this->max_projects_per_user,
            'max_active_deployments_per_user' => $this->max_active_deployments_per_user,
            'max_active_deployments_per_project' => $this->max_active_deployments_per_project,
            'current_projects_count' => $this->current_projects_count,
            'current_active_deployments_count' => $this->current_active_deployments_count,
        ];
    }
}
