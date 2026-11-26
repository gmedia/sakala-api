<?php

declare(strict_types=1);

namespace App\Data\Edge;

/**
 * One verified custom domain in the Edge route table (`custom_routes`,
 * ADR-024): the Edge writes one site for `host` and sends its requests, with
 * the Host header kept, to any of `upstreams`. Empty upstreams mean the
 * project has no workload on a node that serves custom domains: the Edge
 * answers 404 itself, and still keeps the certificate.
 */
final readonly class EdgeCustomRouteData
{
    /**
     * @param  list<string>  $upstreams  Edge addresses (`host:port`), sorted; may be empty.
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
