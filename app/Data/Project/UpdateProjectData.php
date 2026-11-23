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
        /** Applies from the next deployment. */
        public ?int $replicas = null,
        /** Null places the project in any region; only stored when $regionProvided. */
        public ?string $region = null,
        public bool $regionProvided = false,
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
            'replicas' => $this->replicas,
        ], fn (mixed $value): bool => $value !== null);

        if ($this->thumbnailUrlProvided) {
            $data['thumbnail_url'] = $this->thumbnailUrl;
        }

        // Setting auto-deploy either way ends a pause after a rollback: on
        // resumes it, off keeps it off without the pause.
        if ($this->autoDeploy !== null) {
            $data['auto_deploy'] = $this->autoDeploy;
            $data['auto_deploy_paused_at'] = null;
            $data['auto_deploy_paused_by_deployment_id'] = null;
        }

        // A region only applies to placing a project without a workload, so
        // DeploymentConfigFingerprint leaves it out: it never asks for a redeploy.
        if ($this->regionProvided) {
            $data['region'] = $this->region;
        }

        return [...$data, ...$this->buildConfiguration];
    }
}
