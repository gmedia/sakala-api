<?php

declare(strict_types=1);

namespace App\Data\Project;

final readonly class UpdateProjectData
{
    public function __construct(
        public ?string $name = null,
        public ?string $thumbnailUrl = null,
        public ?string $repositoryUrl = null,
        public ?string $branch = null,
    ) {}

    /**
     * Convert DTO to array filtering out null values.
     *
     * @return array<string, mixed>
     */
    public function toDatabaseArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'thumbnail_url' => $this->thumbnailUrl,
            'repository_url' => $this->repositoryUrl,
            'branch' => $this->branch,
        ], fn (mixed $value): bool => $value !== null);
    }
}
