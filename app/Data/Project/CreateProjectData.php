<?php

declare(strict_types=1);

namespace App\Data\Project;

readonly class CreateProjectData
{
    public function __construct(
        public string $name,
        public string $repositoryUrl,
        public string $branch,
        public ?string $repositoryProvider = null,
        public ?string $repositoryFullName = null,
    ) {}

    /**
     * Get the server-owned fields that should be set by the application.
     *
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'repository_url' => $this->repositoryUrl,
            'branch' => $this->branch,
            'repository_provider' => $this->repositoryProvider ?? 'github',
            'repository_full_name' => $this->repositoryFullName,
        ];
    }
}
