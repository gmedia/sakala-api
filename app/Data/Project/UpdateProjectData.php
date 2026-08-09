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

        return $data;
    }
}
