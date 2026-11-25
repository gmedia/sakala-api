<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Data\Edge\EdgeRouteTableData;
use App\Data\Edge\SkippedEdgeRouteData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The route table as the Edge receives it, cached for
 * `sakala.edge.routes_cache_seconds` (0 builds it on every request).
 *
 * A short cache instead of invalidation on change: what changes a route comes
 * from many places (a deployment succeeding, sleep, wake, suspension, stop,
 * deletion, a node reporting or an admin setting an edge address, the project
 * domain setting), and one missed invalidation would serve a stale table
 * until something else changed. A few seconds bound the staleness instead,
 * on the same order as the Edge's own poll interval, while several Edge
 * instances polling cost one build per interval. A failed build is not
 * cached, so the error reaches every caller as a 5xx.
 */
final class GetEdgeRouteTableAction
{
    public const CACHE_KEY = 'edge:route-table';

    /** Last logged set of skipped routes, so a lasting problem is logged once, not on every build. */
    private const SKIPPED_SIGNATURE_CACHE_KEY = 'edge:route-table:skipped-signature';

    public function __construct(
        private readonly BuildEdgeRouteTableAction $build,
    ) {}

    public function handle(): EdgeRouteTableData
    {
        $seconds = (int) config('sakala.edge.routes_cache_seconds');

        if ($seconds <= 0) {
            return $this->build();
        }

        /** @var array{generated_at: string, revision: string, expected_minimum_routes: int, routes: list<array{host: string, project_id: string, upstreams: list<string>}>, skipped_routes: list<array{host: string, project_id: string, reason: string}>, expected_minimum_custom_routes?: int, custom_routes?: list<array{host: string, project_id: string, upstreams: list<string>}>} $table */
        $table = Cache::remember(self::CACHE_KEY, $seconds, fn (): array => $this->build()->toArray());

        return EdgeRouteTableData::fromArray($table);
    }

    private function build(): EdgeRouteTableData
    {
        $table = $this->build->handle();
        $this->logSkippedRoutes($table);

        return $table;
    }

    /** Warns when the set of skipped routes changes to a non-empty one. */
    private function logSkippedRoutes(EdgeRouteTableData $table): void
    {
        $skipped = array_map(static fn (SkippedEdgeRouteData $route): array => $route->toArray(), $table->skippedRoutes);
        $signature = hash('sha256', json_encode($skipped, JSON_THROW_ON_ERROR));

        if ($skipped === []) {
            if (Cache::has(self::SKIPPED_SIGNATURE_CACHE_KEY)) {
                Cache::forget(self::SKIPPED_SIGNATURE_CACHE_KEY);
            }

            return;
        }

        if (Cache::get(self::SKIPPED_SIGNATURE_CACHE_KEY) === $signature) {
            return;
        }

        Cache::forever(self::SKIPPED_SIGNATURE_CACHE_KEY, $signature);

        Log::warning('Edge route table skips projects that hold a workload.', [
            'skipped_count' => count($skipped),
            'skipped_routes' => $skipped,
        ]);
    }
}
