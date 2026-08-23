<?php

declare(strict_types=1);

namespace App\Data\Project;

use App\Enums\GithubRepositorySource;

final readonly class CreateProjectData
{
    public function __construct(
        public string $name,
        public string $branch,
        public GithubRepositorySource $repositorySource,
        public ?string $repositoryUrl = null,
        public ?string $githubInstallationId = null,
        public ?int $githubRepositoryId = null,
    ) {}
}
