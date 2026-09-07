<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Models\AgentCommand;
use App\Models\Project;

final readonly class ProjectControlResultData
{
    /**
     * @param  array<string, mixed>|null  $responseContext
     */
    public function __construct(
        public Project $project,
        public AgentCommand $command,
        public ?array $responseContext = null,
    ) {}
}
