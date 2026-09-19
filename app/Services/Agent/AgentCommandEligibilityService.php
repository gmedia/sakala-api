<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use Illuminate\Database\Eloquent\Builder;

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
     * Protocol revisions this control plane can schedule commands for.
     *
     * @return list<int>
     */
    public function supportedProtocolVersions(): array
    {
        /** @var array<int, mixed> $configured */
        $configured = config('sakala.agent.supported_protocol_versions', [4]);

        return array_values(array_map(
            static fn (mixed $value): int => (int) $value,
            $configured,
        ));
    }

    /**
     * Whether the node reported a protocol revision the API can talk to. A
     * node that has never heartbeated has no revision and is not eligible.
     */
    public function nodeSpeaksSupportedProtocol(AgentNode $node): bool
    {
        return $node->protocol_version !== null
            && in_array($node->protocol_version, $this->supportedProtocolVersions(), true);
    }

    /**
     * Whether the node may receive any command at all: authorised, on a
     * supported protocol revision, and heard from recently enough that it
     * is not derived offline. A draining or drained node is still reachable
     * for its own lifecycle commands.
     */
    public function nodeIsCommandEligible(AgentNode $node): bool
    {
        return $node->auth_status === AgentAuthStatus::Active
            && $this->nodeSpeaksSupportedProtocol($node)
            && $node->status !== AgentNodeStatus::Offline;
    }

    /**
     * Whether the node may receive workload (project) commands: reachable,
     * reporting an operational status, and intended to be active. Nodes
     * that are draining, drained, or in maintenance — by intent or by their
     * own report — only receive node lifecycle commands, matching what the
     * agent processes locally.
     */
    public function nodeAcceptsWorkload(AgentNode $node): bool
    {
        return $this->nodeIsCommandEligible($node)
            && in_array($node->status, $this->activeNodeStatuses(), true)
            && $node->desired_state === AgentNodeDesiredState::Active;
    }

    /**
     * Whether the node holds at least one capability required by the command
     * type. Command types without capability requirements are always allowed.
     */
    public function nodeHasCapabilityFor(AgentNode $node, AgentCommandType $type): bool
    {
        $required = $type->requiredCapabilities();

        if ($required === []) {
            return true;
        }

        $nodeCaps = $node->capabilities ?? [];

        return collect($required)
            ->intersect($nodeCaps)
            ->isNotEmpty();
    }

    /**
     * Command type values the node can execute right now, combining protocol,
     * authorisation, reported status, desired lifecycle state, and capability.
     *
     * @return list<string>
     */
    public function eligibleTypeValues(AgentNode $node): array
    {
        if (! $this->nodeIsCommandEligible($node)) {
            return [];
        }

        $acceptsWorkload = $this->nodeAcceptsWorkload($node);

        $values = collect(AgentCommandType::cases())
            ->filter(fn (AgentCommandType $type): bool => $acceptsWorkload || $type->isNodeLifecycle())
            ->filter(fn (AgentCommandType $type): bool => $this->nodeHasCapabilityFor($node, $type))
            ->map(fn (AgentCommandType $type): string => $type->value)
            ->all();

        return array_values($values);
    }

    /**
     * Whether a command's target binding allows this node to see or claim it.
     * Pinned and node-level commands must already be assigned to the node;
     * other commands may be unassigned or assigned to the node.
     */
    public function commandIsScopedToNode(AgentCommand $command, AgentNode $node): bool
    {
        if ($command->agent_node_id === $node->id) {
            return true;
        }

        return $command->agent_node_id === null && ! $command->type->isPinnedAtCreation();
    }

    /**
     * SQL form of {@see commandIsScopedToNode()} for polling.
     *
     * @param  Builder<AgentCommand>  $query
     * @return Builder<AgentCommand>
     */
    public function applyNodeScopeToQuery(Builder $query, AgentNode $node): Builder
    {
        $pinnedTypes = collect(AgentCommandType::cases())
            ->filter(fn (AgentCommandType $type): bool => $type->isPinnedAtCreation())
            ->map(fn (AgentCommandType $type): string => $type->value)
            ->values()
            ->all();

        return $query->where(function (Builder $query) use ($node, $pinnedTypes): void {
            $query->where('agent_node_id', $node->id)
                ->orWhere(function (Builder $query) use ($pinnedTypes): void {
                    $query->whereNull('agent_node_id')
                        ->whereNotIn('type', $pinnedTypes);
                });
        });
    }

    /**
     * Full node-side eligibility check for one command type. Used by poll and
     * by claim re-validation.
     */
    public function nodeIsEligibleFor(AgentNode $node, AgentCommandType $type): bool
    {
        return in_array($type->value, $this->eligibleTypeValues($node), true);
    }
}
