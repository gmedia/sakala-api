<?php

declare(strict_types=1);

namespace App\Data\Project;

final readonly class UpdateProjectData
{
    public function __construct(
        public ?string $name = null,
        public ?string $thumbnailUrl = null,
        public ?string $branch = null,
        public bool $thumbnailUrlProvided = false,
        /** @var array<string, int|string|null> Only the fields the request sent. */
        public array $buildConfiguration = [],
        public ?bool $autoDeploy = null,
    ) {}

    /**
     * Convert DTO to array filtering out null values.
     *
     * @return array<string, mixed>
     */
    public function toDatabaseArray(): array
    {
        $data = array_filter([
            'name' => $this->name,
            'branch' => $this->branch,
        ], fn (mixed $value): bool => $value !== null);

        if ($this->thumbnailUrlProvided) {
            $data['thumbnail_url'] = $this->thumbnailUrl;
        }

        if ($this->autoDeploy !== null) {
            $data['auto_deploy'] = $this->autoDeploy;
        }

        return [...$data, ...$this->buildConfiguration];
    }
}
