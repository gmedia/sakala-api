<?php

declare(strict_types=1);

namespace App\Data\Agent;

use App\Models\AgentNode;

/**
 * Where a command's replicas go: one assignment per node with how many
 * replicas it runs. Today the scheduler always returns at most one assignment
 * that holds every replica, because load balancing between nodes needs Sakala
 * Edge to spread a project's traffic across them (ADR-019 stage 4). The shape
 * already allows several, so spreading later changes the scheduler, not its
 * callers' contract.
 */
final readonly class ReplicaPlacementData
{
    /**
     * @param  list<array{node: AgentNode, replicas: int}>  $assignments  Empty when no node can take the command now.
     */
    public function __construct(
        public array $assignments,
    ) {}

    public static function none(): self
    {
        return new self([]);
    }

    public static function onOneNode(AgentNode $node, int $replicas): self
    {
        return new self([['node' => $node, 'replicas' => $replicas]]);
    }

    /** The node the command is pinned to: the first assignment, or null without one. */
    public function primaryNode(): ?AgentNode
    {
        return $this->assignments[0]['node'] ?? null;
    }
}
