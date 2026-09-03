<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Models\AgentNode;

/**
 * Single source of truth for agent command eligibility policy.
 *
 * Both polling and claim must use this service so the rules cannot drift
 * between the two endpoints.
 */
final class AgentCommandEligibilityService
{
    /**
     * Runtime node states that may receive workload commands.
     *
     * @return list<AgentNodeStatus>
     */
    public function activeNodeStatuses(): array
    {
        return [
            AgentNodeStatus::Ready,
            AgentNodeStatus::Busy,
            AgentNodeStatus::Degraded,
        ];
    }

    /**
     * Whether the node is in an operational state that may receive workload
     * commands. Draining, drained, maintenance, and offline nodes are not.
     */
    public function nodeIsCommandEligible(AgentNode $node): bool
    {
        return in_array($node->status, $this->activeNodeStatuses(), true);
    }

    /**
     * Whether the node holds at least one capability required by the command
     * type.
     */
    public function nodeHasCapabilityFor(AgentNode $node, AgentCommandType $type): bool
    {
        $nodeCaps = $node->capabilities ?? [];

        return collect($type->requiredCapabilities())
            ->intersect($nodeCaps)
            ->isNotEmpty();
    }

    /**
     * Command type values the node can execute, based on its capability set.
     *
     * @return list<string>
     */
    public function eligibleTypeValues(AgentNode $node): array
    {
        $values = collect(AgentCommandType::cases())
            ->filter(fn (AgentCommandType $type): bool => $this->nodeHasCapabilityFor($node, $type))
            ->map(fn (AgentCommandType $type): string => $type->value)
            ->all();

        return array_values($values);
    }

    /**
     * Full node-side eligibility check: operational state plus capability
     * for the given command type. Used by poll and by claim re-validation.
     */
    public function nodeIsEligibleFor(AgentNode $node, AgentCommandType $type): bool
    {
        return $this->nodeIsCommandEligible($node) && $this->nodeHasCapabilityFor($node, $type);
    }
}
