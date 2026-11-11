<?php

declare(strict_types=1);

namespace App\Data\Agent;

/**
 * Typed view of the `ProjectInspection` result an agent sends when
 * completing InspectProject. Tolerant of missing keys (the noop runtime
 * sends `null`, and older agents omit `exposed_ports`).
 */
final readonly class ProjectInspectionResultData
{
    /**
     * @param  list<string>  $manifests
     * @param  list<int>|null  $exposedPorts  TCP ports from `EXPOSE` in the final
     *                                        Dockerfile stage; null when the agent did not report them
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
        public ?array $exposedPorts,
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
        $exposedPorts = $result['exposed_ports'] ?? null;

        return new self(
            repositoryUrl: is_string($result['repository_url'] ?? null) ? $result['repository_url'] : null,
            commitSha: is_string($result['commit_sha'] ?? null) ? $result['commit_sha'] : null,
            dockerfileFound: ($result['dockerfile_found'] ?? false) === true,
            envExampleFound: ($result['env_example_found'] ?? false) === true,
            composeFound: ($result['compose_found'] ?? false) === true,
            manifests: is_array($manifests) ? array_values(array_filter($manifests, 'is_string')) : [],
            packageManager: is_string($result['package_manager'] ?? null) ? $result['package_manager'] : null,
            exposedPorts: is_array($exposedPorts) ? self::ports($exposedPorts) : null,
            railpack: is_array($railpack) ? $railpack : null,
        );
    }

    /**
     * The port to use when the project has none of its own: only an
     * unambiguous single exposed port counts.
     */
    public function detectedPort(): ?int
    {
        return $this->exposedPorts !== null && count($this->exposedPorts) === 1
            ? $this->exposedPorts[0]
            : null;
    }

    /**
     * @param  array<array-key, mixed>  $ports
     * @return list<int>
     */
    private static function ports(array $ports): array
    {
        $valid = array_filter(
            $ports,
            static fn (mixed $port): bool => is_int($port) && $port >= 1 && $port <= 65535,
        );

        /** @var list<int> */
        return array_values(array_unique($valid));
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
            'exposed_ports' => $this->exposedPorts,
            'railpack' => $this->railpack,
        ];
    }
}
