<?php

declare(strict_types=1);

namespace App\Data\Edge;

/**
 * One project in the Edge route table: requests for `host` go to any of
 * `upstreams`, each a node's runtime router, with the Host header kept.
 */
final readonly class EdgeRouteData
{
    /**
     * @param  list<string>  $upstreams  Edge addresses (`host:port`) of the nodes holding the project's workload, sorted, at least one.
     */
    public function __construct(
        public string $host,
        public string $projectId,
        public array $upstreams,
    ) {}

    /** @return array{host: string, project_id: string, upstreams: list<string>} */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'project_id' => $this->projectId,
            'upstreams' => $this->upstreams,
        ];
    }

    /** @param  array{host: string, project_id: string, upstreams: list<string>}  $route */
    public static function fromArray(array $route): self
    {
        return new self($route['host'], $route['project_id'], $route['upstreams']);
    }
}
