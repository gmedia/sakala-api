<?php

declare(strict_types=1);

namespace App\Data\Agent;

/**
 * Typed view of the `ProjectInspection` result an agent sends when
 * completing InspectProject. Tolerant of missing keys (the noop runtime
 * sends `null`).
 */
final readonly class ProjectInspectionResultData
{
    /**
     * @param  list<string>  $manifests
     * @param  array<string, mixed>|null  $railpack
     */
    public function __construct(
        public ?string $repositoryUrl,
        public ?string $commitSha,
        public bool $dockerfileFound,
        public bool $envExampleFound,
        public bool $composeFound,
        public array $manifests,
        public ?string $packageManager,
        public ?array $railpack,
    ) {}

    /**
     * @param  array<string, mixed>|null  $result
     */
    public static function fromArray(?array $result): self
    {
        $result ??= [];

        $manifests = $result['manifests'] ?? [];
        $railpack = $result['railpack'] ?? null;

        return new self(
            repositoryUrl: is_string($result['repository_url'] ?? null) ? $result['repository_url'] : null,
            commitSha: is_string($result['commit_sha'] ?? null) ? $result['commit_sha'] : null,
            dockerfileFound: ($result['dockerfile_found'] ?? false) === true,
            envExampleFound: ($result['env_example_found'] ?? false) === true,
            composeFound: ($result['compose_found'] ?? false) === true,
            manifests: is_array($manifests) ? array_values(array_filter($manifests, 'is_string')) : [],
            packageManager: is_string($result['package_manager'] ?? null) ? $result['package_manager'] : null,
            railpack: is_array($railpack) ? $railpack : null,
        );
    }

    /**
     * Shape persisted on the project. The raw railpack output is kept for
     * forward compatibility but is not part of the console contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'repository_url' => $this->repositoryUrl,
            'commit_sha' => $this->commitSha,
            'dockerfile_found' => $this->dockerfileFound,
            'env_example_found' => $this->envExampleFound,
            'compose_found' => $this->composeFound,
            'manifests' => $this->manifests,
            'package_manager' => $this->packageManager,
            'railpack' => $this->railpack,
        ];
    }
}
