<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeDesiredState;
use App\Models\AgentNode;

/**
 * Regions a project may choose: the distinct effective regions
 * (AgentNode::effectiveRegion()) of nodes that are allowed work, whether or
 * not they are online right now. A region whose nodes are all revoked or
 * drained disappears. Nodes are few, so the effective region, which may come
 * from heartbeat metadata, is resolved in PHP.
 */
final class GetAvailableRegionsAction
{
    /** @return list<string> Sorted. */
    public function handle(): array
    {
        $regions = AgentNode::query()
            ->where('auth_status', AgentAuthStatus::Active)
            ->where('desired_state', AgentNodeDesiredState::Active)
            ->get(['id', 'region_override', 'metadata'])
            ->map(static fn (AgentNode $node): ?string => $node->effectiveRegion())
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        /** @var list<string> $regions */
        return $regions;
    }
}
