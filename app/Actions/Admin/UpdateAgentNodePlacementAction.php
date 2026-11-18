<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\AgentNode;
use App\Support\Admin\AdminAudit;
use Illuminate\Support\Facades\DB;

/**
 * Sets or clears the admin overrides of a node's region and edge address.
 * An override wins over what the node reports in its heartbeat; null goes
 * back to the reported value. Callers validate the value (App\Rules\RegionSlug,
 * App\Rules\EdgeAddress). Edge routes follow within
 * `sakala.edge.routes_cache_seconds`.
 */
final class UpdateAgentNodePlacementAction
{
    public const REGION_AUDIT_ACTION = 'agent_node.region_changed';

    public const EDGE_ADDRESS_AUDIT_ACTION = 'agent_node.edge_address_changed';

    public function setRegion(AgentNode $node, ?string $region, string $reason): AgentNode
    {
        return $this->set($node, 'region_override', $region, self::REGION_AUDIT_ACTION, $reason);
    }

    public function setEdgeAddress(AgentNode $node, ?string $address, string $reason): AgentNode
    {
        return $this->set($node, 'edge_address_override', $address, self::EDGE_ADDRESS_AUDIT_ACTION, $reason);
    }

    private function set(AgentNode $node, string $column, ?string $value, string $auditAction, string $reason): AgentNode
    {
        return DB::transaction(function () use ($node, $column, $value, $auditAction, $reason): AgentNode {
            /** @var AgentNode $locked */
            $locked = AgentNode::query()->lockForUpdate()->findOrFail($node->id);
            $before = $locked->getAttribute($column);

            if ($before === $value) {
                return $locked;
            }

            $locked->forceFill([$column => $value])->save();

            AdminAudit::recordFromConsole($auditAction, $locked, $reason, ['from' => $before, 'to' => $value]);

            return $locked;
        });
    }
}
