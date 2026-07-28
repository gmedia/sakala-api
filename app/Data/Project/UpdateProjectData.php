<?php

declare(strict_types=1);

namespace App\Data\Project;

readonly class UpdateProjectData
{
    public function __construct(
        public ?string $name = null,
        public ?string $repositoryUrl = null,
        public ?string $branch = null,
        public ?string $repositoryProvider = null,
        public ?string $repositoryFullName = null,
    ) {}

    /**
     * Get the fields that are allowed to be updated.
     *
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }

        if ($this->repositoryUrl !== null) {
            $data['repository_url'] = $this->repositoryUrl;
        }

        if ($this->branch !== null) {
            $data['branch'] = $this->branch;
        }

        if ($this->repositoryProvider !== null) {
            $data['repository_provider'] = $this->repositoryProvider;
        }

        if ($this->repositoryFullName !== null) {
            $data['repository_full_name'] = $this->repositoryFullName;
        }

        return $data;
    }
}
