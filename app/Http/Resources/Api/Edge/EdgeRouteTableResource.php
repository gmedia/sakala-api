<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Edge;

use App\Data\Edge\EdgeRouteTableData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Edge route table, unwrapped (no `data` key). Normative description in
 * docs/EDGE_API.md.
 *
 * @property EdgeRouteTableData $resource
 */
final class EdgeRouteTableResource extends JsonResource
{
    /** Any cache must revalidate with the ETag (the revision) before reusing the table. */
    public const CACHE_CONTROL = 'private, no-cache';

    /** @var string|null */
    public static $wrap = null;

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setEtag($this->resource->revision);
        $response->headers->set('Cache-Control', self::CACHE_CONTROL);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $table = $this->resource->toArray();

        return [
            /** When the API built this table (RFC 3339); up to `SAKALA_EDGE_ROUTES_CACHE_SECONDS` old. */
            'generated_at' => $table['generated_at'],
            /** SHA-256 (lowercase hex) of `expected_minimum_routes`, `routes`, and `skipped_routes`; also the ETag. */
            'revision' => $table['revision'],
            /** Routes the API built; a response with fewer `routes` is truncated and must be rejected. */
            'expected_minimum_routes' => $table['expected_minimum_routes'],
            /**
             * One route per project that holds a workload on a node, sorted by `host`.
             *
             * @var list<array{host: string, project_id: string, upstreams: list<string>}>
             */
            'routes' => $table['routes'],
            /**
             * Projects that hold a workload but have no route, sorted by `host`. `reason` is
             * `node_without_edge_address` or `invalid_host`.
             *
             * @var list<array{host: string, project_id: string, reason: string}>
             */
            'skipped_routes' => $table['skipped_routes'],
        ];
    }
}
