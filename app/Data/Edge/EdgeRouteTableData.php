<?php

declare(strict_types=1);

namespace App\Data\Edge;

use Carbon\CarbonImmutable;

/**
 * The route table Sakala Edge pulls (docs/EDGE_API.md). Kept as plain arrays
 * in the cache (toArray()/fromArray()), because the cache does not
 * unserialize objects.
 */
final readonly class EdgeRouteTableData
{
    /**
     * @param  list<EdgeRouteData>  $routes  Sorted by host.
     * @param  list<SkippedEdgeRouteData>  $skippedRoutes  Sorted by host.
     */
    public function __construct(
        public CarbonImmutable $generatedAt,
        public string $revision,
        public int $expectedMinimumRoutes,
        public array $routes,
        public array $skippedRoutes,
    ) {}

    /**
     * Builds the table and its revision: the SHA-256 (lowercase hex) of the
     * JSON of everything but `generated_at` and `revision`, so the revision
     * only changes when what the Edge acts on changes.
     *
     * @param  list<EdgeRouteData>  $routes
     * @param  list<SkippedEdgeRouteData>  $skippedRoutes
     */
    public static function build(CarbonImmutable $generatedAt, array $routes, array $skippedRoutes): self
    {
        $content = [
            'expected_minimum_routes' => count($routes),
            'routes' => array_map(static fn (EdgeRouteData $route): array => $route->toArray(), $routes),
            'skipped_routes' => array_map(static fn (SkippedEdgeRouteData $skipped): array => $skipped->toArray(), $skippedRoutes),
        ];

        return new self(
            generatedAt: $generatedAt,
            revision: hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            expectedMinimumRoutes: $content['expected_minimum_routes'],
            routes: $routes,
            skippedRoutes: $skippedRoutes,
        );
    }

    /**
     * @return array{generated_at: string, revision: string, expected_minimum_routes: int, routes: list<array{host: string, project_id: string, upstreams: list<string>}>, skipped_routes: list<array{host: string, project_id: string, reason: string}>}
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt->toRfc3339String(),
            'revision' => $this->revision,
            'expected_minimum_routes' => $this->expectedMinimumRoutes,
            'routes' => array_map(static fn (EdgeRouteData $route): array => $route->toArray(), $this->routes),
            'skipped_routes' => array_map(static fn (SkippedEdgeRouteData $skipped): array => $skipped->toArray(), $this->skippedRoutes),
        ];
    }

    /**
     * @param  array{generated_at: string, revision: string, expected_minimum_routes: int, routes: list<array{host: string, project_id: string, upstreams: list<string>}>, skipped_routes: list<array{host: string, project_id: string, reason: string}>}  $table
     */
    public static function fromArray(array $table): self
    {
        return new self(
            generatedAt: CarbonImmutable::parse($table['generated_at']),
            revision: $table['revision'],
            expectedMinimumRoutes: $table['expected_minimum_routes'],
            routes: array_map(EdgeRouteData::fromArray(...), $table['routes']),
            skippedRoutes: array_map(SkippedEdgeRouteData::fromArray(...), $table['skipped_routes']),
        );
    }
}
