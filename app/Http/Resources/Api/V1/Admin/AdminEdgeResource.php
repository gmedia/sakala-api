<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Admin\AdminEdgeData;
use App\Enums\EdgeHealthReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Sakala Edge instance (its token) and its health, judged now by the
 * rules of `edge:check-health`. Never the token, its hash, or its prefix.
 *
 * @property AdminEdgeData $resource
 */
final class AdminEdgeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $token = $this->resource->token;
        $health = $this->resource->health;

        return [
            /** The Edge instance, as given to `edge:token`. */
            'name' => $token->name,
            /** The last authenticated request, recorded at most once a minute while nothing changes; null when never seen. */
            'last_seen_at' => $token->last_used_at?->toAtomString(),
            /** The sakala-edge version from `User-Agent`; null when unknown. */
            'last_version' => $token->last_version,
            /** The revision the Edge serves (64 lowercase hex), `none` before its first apply, or null when unknown. */
            'applied_revision' => $token->last_applied_revision,
            /** The revision of the route table now; the same on every item. */
            'current_revision' => $this->resource->currentRevision,
            /** `ok`, or the code sakala-edge failed to apply the table with; null when unknown. */
            'status' => $token->last_status,
            /** Null for a revoked token or one never seen: not evaluated, never alerted. */
            'healthy' => $health->healthy,
            /**
             * The conditions that held longer than `SAKALA_EDGE_STALE_AFTER_SECONDS`: `stale`, `status`,
             * `revision_behind`. Empty when healthy or unknown.
             *
             * @var list<string>
             */
            'unhealthy_reasons' => array_map(static fn (EdgeHealthReason $reason): string => $reason->value, $health->reasons),
            /** The earliest moment one of `unhealthy_reasons` crossed the threshold; null unless unhealthy. */
            'unhealthy_since' => $health->unhealthySince?->toAtomString(),
            'revoked' => $token->isRevoked(),
        ];
    }
}
