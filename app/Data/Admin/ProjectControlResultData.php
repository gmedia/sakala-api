<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Models\AgentCommand;
use App\Models\Project;

final readonly class ProjectControlResultData
{
    public function __construct(
        public Project $project,
        public AgentCommand $command,
    ) {}
}
