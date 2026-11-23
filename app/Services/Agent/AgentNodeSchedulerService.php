<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Data\Agent\ReplicaPlacementData;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\DeploymentStatus;
use App\Exceptions\Bucket\BucketCapacityUnavailableException;
use App\Exceptions\Bucket\BucketRegionUnavailableException;
use App\Exceptions\Database\DatabaseRegionUnavailableException;
use App\Exceptions\Runtime\ProjectRegionUnavailableException;
use App\Exceptions\Runtime\ProjectReleaseCommandUnsupportedException;
use App\Exceptions\Runtime\ProjectReplicasUnsupportedException;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\ManagedBucket;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Support\Collection;

/**
 * Pick the runtime node a pinned command is created for. The control plane
 * decides the target before the command is ever offered, so secrets are only
 * materialised for one authenticated node and lifecycle commands land on the
 * node that owns the workload.
 */
final class AgentNodeSchedulerService
{
    /** Attribute eligibleNodes() adds: open DeployProject commands pinned to the node. */
    private const RESERVED_DEPLOYMENTS_ATTRIBUTE = 'reserved_deployments_count';

    /** Bytes in one MB, for the memory a reserved deployment is assumed to take. */
    private const BYTES_PER_MEGABYTE = 1024 * 1024;

    public function __construct(
        private readonly AgentCommandEligibilityService $eligibility,
        private readonly PilotRuntimeLimitService $limits,
    ) {}

    /**
     * Return the node that should execute a command of the given type for the
     * project, or null when no node can take it right now (the command then
     * stays unassigned until a later sweep or heartbeat assigns it). The
     * primary node of placementFor().
     */
    public function selectNodeFor(
        AgentCommandType $type,
        ?Project $project = null,
        int $replicas = Project::DEFAULT_REPLICAS,
        bool $releaseCommand = false,
    ): ?AgentNode {
        return $this->placementFor($type, $project, $replicas, $releaseCommand)->primaryNode();
    }

    /**
     * Where a command of the given type for the project runs, in this order:
     *
     * 1. Only eligible nodes (eligibleNodes()) that run every replica and,
     *    for a deployment with a release command, run that too
     *    (AgentNode::canRunDeployment()).
     * 2. A project already served by a node stays there, whatever its region
     *    and capacity, or waits for it (see below).
     * 3. Otherwise only nodes of the project's region
     *    (PilotRuntimeLimitService::regionFor()), when it has one.
     * 4. Among those, the node with the most free capacity (rankByCapacity()).
     *
     * All replicas go to one node. TODO(ADR-019 stage 4): once Sakala Edge
     * balances a project across nodes (the route table already lists several
     * upstreams), spread replicas over the eligible nodes of the region here;
     * ReplicaPlacementData already carries several assignments.
     */
    public function placementFor(
        AgentCommandType $type,
        ?Project $project = null,
        int $replicas = Project::DEFAULT_REPLICAS,
        bool $releaseCommand = false,
    ): ReplicaPlacementData {
        $candidates = $this->eligibleNodes($type)
            ->filter(static fn (AgentNode $node): bool => $node->canRunDeployment($replicas, $releaseCommand))
            ->values();

        if ($project !== null) {
            // Routes, images, and containers are node-local: a project that is
            // already served by a node must stay there so stop/finalization
            // commands can reach the running workload. If that node cannot take
            // work right now the command waits for it; moving to another node
            // would leave the old workload running unreachable, so cross-node
            // migration is deliberately not implicit. A region changed later
            // applies once the workload is gone.
            $currentNodeId = $this->currentNodeIdOf($project);

            if ($currentNodeId !== null) {
                $current = $candidates->firstWhere('id', $currentNodeId);

                return $current === null ? ReplicaPlacementData::none() : ReplicaPlacementData::onOneNode($current, $replicas);
            }

            $candidates = $this->inRegion($candidates, $this->limits->regionFor($project));
        }

        $node = $this->rankByCapacity($candidates, $replicas)->first();

        return $node === null ? ReplicaPlacementData::none() : ReplicaPlacementData::onOneNode($node, $replicas);
    }

    /**
     * Refuse a deployment that no node could ever run, so it is not queued
     * forever. Nothing to check when a node was selected. Otherwise the node
     * the project is pinned to, whatever its state, or else every node of the
     * project's region that is allowed work, must exist and run all
     * `$replicas` and, when `$releaseCommand`, the release command. A pinned
     * or known node that only needs to come back online is fine; the
     * deployment waits for it.
     *
     * @throws ProjectRegionUnavailableException
     * @throws ProjectReplicasUnsupportedException
     * @throws ProjectReleaseCommandUnsupportedException
     */
    public function ensurePlaceable(Project $project, ?AgentNode $selected, int $replicas, bool $releaseCommand = false): void
    {
        if ($selected !== null) {
            return;
        }

        $currentNodeId = $this->currentNodeIdOf($project);
        $region = $currentNodeId === null ? $this->limits->regionFor($project) : null;

        if ($region === null && $replicas <= Project::DEFAULT_REPLICAS && ! $releaseCommand) {
            return;
        }

        /** @var Collection<int, AgentNode> $nodes */
        $nodes = AgentNode::query()
            ->when(
                $currentNodeId !== null,
                fn ($query) => $query->whereKey($currentNodeId),
                fn ($query) => $query
                    ->where('auth_status', AgentAuthStatus::Active)
                    ->where('desired_state', AgentNodeDesiredState::Active),
            )
            ->get();

        $nodes = $this->inRegion($nodes, $region);

        if ($region !== null && $nodes->isEmpty()) {
            throw new ProjectRegionUnavailableException($region);
        }

        if ($nodes->contains(static fn (AgentNode $node): bool => $node->canRunDeployment($replicas, $releaseCommand))) {
            return;
        }

        // Replicas first: their answer says how many would fit. Only when some
        // node runs them all is the release command what is missing.
        if ($replicas > Project::DEFAULT_REPLICAS
            && ! $nodes->contains(static fn (AgentNode $node): bool => $node->supportsReplicas($replicas))) {
            throw new ProjectReplicasUnsupportedException(
                $replicas,
                $nodes->map(static fn (AgentNode $node): ?int => $node->maximumReplicasPerProject())->filter()->max(),
            );
        }

        throw new ProjectReleaseCommandUnsupportedException;
    }

    /**
     * The database node a new managed database goes to (ADR-020): a node with
     * the `managed-postgres` capability in `$region`, or in any region when it
     * is null. Among eligible nodes (online, active, supported protocol) those
     * whose instance answered at the last reading (`metadata.database.available`)
     * come first, then the one hosting the fewest live databases, then the
     * freshest heartbeat.
     * When none is online, a node of the region that is allowed work and last
     * advertised the capability is chosen anyway, and the database waits for
     * it like a deployment waits for its node. A database never moves, so the
     * choice is final.
     *
     * @throws DatabaseRegionUnavailableException when no node of the region may ever host it.
     */
    public function selectDatabaseNode(?string $region): AgentNode
    {
        $online = $this->inRegion($this->eligibleNodes(AgentCommandType::CreateDatabase), $region);

        if ($online->isNotEmpty()) {
            /** @var AgentNode */
            return $this->rankByDatabaseLoad($online)->first();
        }

        /** @var Collection<int, AgentNode> $allowed */
        $allowed = AgentNode::query()
            ->where('auth_status', AgentAuthStatus::Active)
            ->where('desired_state', AgentNodeDesiredState::Active)
            ->get()
            ->filter(static fn (AgentNode $node): bool => $node->hasCapability(AgentCommandType::MANAGED_POSTGRES_CAPABILITY));

        $node = $this->rankByDatabaseLoad($this->inRegion($allowed, $region))->first();

        if ($node === null) {
            throw new DatabaseRegionUnavailableException($region);
        }

        return $node;
    }

    /**
     * Whether this installation can host a new managed database right now:
     * an online node that may receive CreateDatabase (capability
     * `managed-postgres`, same eligibility as placement) and whose last
     * reading said its instance answers. Stricter than selectDatabaseNode(),
     * which also queues for an offline node, because this answer decides
     * whether the Console offers databases at all.
     */
    public function databasesAvailable(): bool
    {
        return $this->eligibleNodes(AgentCommandType::CreateDatabase)
            ->contains(static fn (AgentNode $node): bool => $node->databaseReportedAvailable());
    }

    /**
     * The storage node a new managed bucket goes to (ADR-021): a node with the
     * `managed-s3` capability in `$region`, or in any region when it is null,
     * whose reported `capacity_mb` minus what its buckets already reserve
     * (ManagedBucket::reservedMbOnNode()) fits `$quotaMb`. Among online
     * eligible nodes (active, supported protocol) with room, those whose
     * instance answered at the last reading come first, then the most free
     * allocation, then the freshest heartbeat. When no online node has room,
     * an allowed node that is offline and has room is chosen, and the bucket
     * waits for it. A bucket never moves, so the choice is final.
     *
     * The capacity check reads without locking the node, so two creates in
     * different workspaces may both fit the last MB here; the agent rechecks
     * its real total quota and disk before applying one, and the loser fails
     * with `bucket_capacity_exceeded`.
     *
     * @throws BucketRegionUnavailableException when no node of the region may ever host it.
     * @throws BucketCapacityUnavailableException when nodes exist but none has room.
     */
    public function selectBucketNode(?string $region, int $quotaMb): AgentNode
    {
        $online = $this->inRegion($this->eligibleNodes(AgentCommandType::CreateBucket), $region);

        /** @var Collection<int, AgentNode> $allowed */
        $allowed = AgentNode::query()
            ->where('auth_status', AgentAuthStatus::Active)
            ->where('desired_state', AgentNodeDesiredState::Active)
            ->whereIn('protocol_version', $this->eligibility->supportedProtocolVersions())
            ->get()
            ->filter(static fn (AgentNode $node): bool => $node->hasCapability(AgentCommandType::MANAGED_S3_CAPABILITY));
        $allowed = $this->inRegion($allowed, $region);

        if ($online->isEmpty() && $allowed->isEmpty()) {
            throw new BucketRegionUnavailableException($region);
        }

        $free = $this->freeBucketAllocation($online->merge($allowed));
        $fits = static fn (AgentNode $node): bool => ($free[$node->id] ?? -1) >= $quotaMb;
        $node = $this->rankByFreeAllocation($online->filter($fits), $free)->first()
            ?? $this->rankByFreeAllocation($allowed->filter($fits), $free)->first();

        if ($node === null) {
            throw new BucketCapacityUnavailableException;
        }

        return $node;
    }

    /**
     * Whether the bucket's own node still has room to raise its quota to
     * `$quotaMb`: capacity minus every other bucket's reservation on it.
     *
     * @throws BucketCapacityUnavailableException
     */
    public function ensureBucketNodeFits(ManagedBucket $bucket, int $quotaMb): void
    {
        $capacity = $bucket->node?->objectStorage()?->capacityMb;

        if ($capacity === null || $capacity - ManagedBucket::reservedMbOnNode($bucket->node_id, except: $bucket->id) < $quotaMb) {
            throw new BucketCapacityUnavailableException;
        }
    }

    /**
     * Whether this installation can host a new managed bucket right now: an
     * online node that may receive CreateBucket (capability `managed-s3`,
     * same eligibility as placement) and whose last reading did not say its
     * instance is down (`metadata.object_storage.available !== false`), with
     * its object storage reported. Same rule as databasesAvailable().
     */
    public function bucketsAvailable(): bool
    {
        return $this->eligibleNodes(AgentCommandType::CreateBucket)
            ->contains(static fn (AgentNode $node): bool => $node->objectStorage() !== null && $node->objectStorageAvailable());
    }

    /**
     * Available instances first, then the most free allocation, then the
     * freshest heartbeat.
     *
     * @param  Collection<int, AgentNode>  $nodes
     * @param  array<string, int>  $free
     * @return Collection<int, AgentNode>
     */
    private function rankByFreeAllocation(Collection $nodes, array $free): Collection
    {
        return $nodes
            ->sortBy([
                static fn (AgentNode $a, AgentNode $b): int => $b->objectStorageAvailable() <=> $a->objectStorageAvailable(),
                static fn (AgentNode $a, AgentNode $b): int => ($free[$b->id] ?? -1) <=> ($free[$a->id] ?? -1),
                static fn (AgentNode $a, AgentNode $b): int => ($b->last_seen_at?->getTimestamp() ?? 0) <=> ($a->last_seen_at?->getTimestamp() ?? 0),
            ])
            ->values();
    }

    /**
     * MB each node may still allocate: reported `capacity_mb` minus what its
     * live buckets reserve. A node that has not reported capacity is absent.
     * One query for the sums, whatever the number of nodes.
     *
     * @param  Collection<int, AgentNode>  $nodes
     * @return array<string, int>
     */
    private function freeBucketAllocation(Collection $nodes): array
    {
        /** @var array<string, int> $reserved */
        $reserved = ManagedBucket::query()
            ->whereIn('node_id', $nodes->pluck('id')->unique()->all())
            ->groupBy('node_id')
            ->selectRaw('node_id, sum(reserved_quota_mb) as aggregate')
            ->pluck('aggregate', 'node_id')
            ->map(static fn (mixed $sum): int => (int) $sum)
            ->all();

        $free = [];

        foreach ($nodes as $node) {
            $capacity = $node->objectStorage()?->capacityMb;

            if ($capacity !== null) {
                $free[$node->id] = $capacity - ($reserved[$node->id] ?? 0);
            }
        }

        return $free;
    }

    /**
     * Available instances first, then fewest live databases, then the freshest
     * heartbeat. One query for the counts, whatever the number of nodes.
     *
     * @param  Collection<int, AgentNode>  $nodes
     * @return Collection<int, AgentNode>
     */
    private function rankByDatabaseLoad(Collection $nodes): Collection
    {
        /** @var array<string, int> $counts */
        $counts = ManagedDatabase::query()
            ->whereIn('node_id', $nodes->pluck('id')->all())
            ->groupBy('node_id')
            ->selectRaw('node_id, count(*) as aggregate')
            ->pluck('aggregate', 'node_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return $nodes
            ->sortBy([
                static fn (AgentNode $a, AgentNode $b): int => $b->databaseAvailable() <=> $a->databaseAvailable(),
                static fn (AgentNode $a, AgentNode $b): int => ($counts[$a->id] ?? 0) <=> ($counts[$b->id] ?? 0),
                static fn (AgentNode $a, AgentNode $b): int => ($b->last_seen_at?->getTimestamp() ?? 0) <=> ($a->last_seen_at?->getTimestamp() ?? 0),
            ])
            ->values();
    }

    /**
     * Nodes whose effective region is `$region`; all of them when it is null.
     *
     * @param  Collection<int, AgentNode>  $nodes
     * @return Collection<int, AgentNode>
     */
    private function inRegion(Collection $nodes, ?string $region): Collection
    {
        if ($region === null) {
            return $nodes;
        }

        return $nodes
            ->filter(static fn (AgentNode $node): bool => $node->effectiveRegion() === $region)
            ->values();
    }

    /**
     * Best node first, by what it has free, in this order:
     *
     * 1. Nodes whose free container slots fit the replicas, or that do not
     *    report a maximum, before nodes that are full.
     * 2. More memory headroom first: available memory from the last
     *    heartbeat, minus the default container memory for each deployment
     *    reserved on the node since (each counted as one container). Nodes
     *    that report no memory come last.
     * 3. More free container slots first, minus the same reservations.
     * 4. Fewer reserved commands, then the freshest heartbeat.
     *
     * Reservations matter because a heartbeat is up to one interval old: a
     * batch of deployments would otherwise all land on the node that looked
     * emptiest at its last report.
     *
     * @param  Collection<int, AgentNode>  $candidates  From eligibleNodes(), with the reservation counts.
     * @return Collection<int, AgentNode>
     */
    private function rankByCapacity(Collection $candidates, int $replicas): Collection
    {
        $containerMemoryBytes = (int) config('sakala.pilot_limits.resources.default_memory_mb', 256) * self::BYTES_PER_MEGABYTE;

        $freeSlots = static function (AgentNode $node): ?int {
            $free = $node->capacity()->freeWorkloadSlots();

            return $free === null ? null : $free - (int) $node->getAttribute(self::RESERVED_DEPLOYMENTS_ATTRIBUTE);
        };

        $fits = static function (AgentNode $node) use ($freeSlots, $replicas): bool {
            $free = $freeSlots($node);

            return $free === null || $free >= $replicas;
        };

        $memoryHeadroom = static function (AgentNode $node) use ($containerMemoryBytes): ?int {
            $available = $node->capacity()->memoryAvailableBytes;

            return $available === null ? null : $available - (int) $node->getAttribute(self::RESERVED_DEPLOYMENTS_ATTRIBUTE) * $containerMemoryBytes;
        };

        // Unknown values sort as the least free.
        $descending = static fn (?int $a, ?int $b): int => ($b ?? PHP_INT_MIN) <=> ($a ?? PHP_INT_MIN);

        return $candidates
            ->sortBy([
                static fn (AgentNode $a, AgentNode $b): int => $fits($b) <=> $fits($a),
                static fn (AgentNode $a, AgentNode $b): int => $descending($memoryHeadroom($a), $memoryHeadroom($b)),
                static fn (AgentNode $a, AgentNode $b): int => $descending($freeSlots($a), $freeSlots($b)),
                ['reserved_commands_count', 'asc'],
                ['last_seen_at', 'desc'],
            ])
            ->values();
    }

    /**
     * The region the project runs in: that of the node serving its latest
     * successful deployment, where it stays, else the region it must be placed
     * in (PilotRuntimeLimitService::regionFor()). Null when neither is known,
     * which means any region.
     */
    public function regionOf(Project $project): ?string
    {
        $currentNodeId = $this->currentNodeIdOf($project);

        if ($currentNodeId !== null) {
            return AgentNode::query()->find($currentNodeId)?->effectiveRegion();
        }

        return $this->limits->regionFor($project);
    }

    /**
     * The node that served the project's latest successful deployment, where
     * its routes, images, and containers live.
     */
    private function currentNodeIdOf(Project $project): ?string
    {
        /** @var string|null $nodeId */
        $nodeId = Deployment::query()
            ->where('project_id', $project->id)
            ->where('status', DeploymentStatus::Succeeded)
            ->whereNotNull('agent_node_id')
            ->orderByDesc('sequence')
            ->value('agent_node_id');

        return $nodeId;
    }

    /**
     * Nodes that may execute the command type right now, freshly heartbeated
     * and with capability checked through the shared eligibility policy.
     *
     * @return Collection<int, AgentNode>
     */
    private function eligibleNodes(AgentCommandType $type): Collection
    {
        $offlineAfter = (int) config('sakala.agent.offline_after_seconds', 60);

        /** @var \Illuminate\Database\Eloquent\Collection<int, AgentNode> $nodes */
        $nodes = AgentNode::query()
            ->where('auth_status', AgentAuthStatus::Active)
            ->where('desired_state', AgentNodeDesiredState::Active)
            ->whereIn('status', $this->eligibility->activeNodeStatuses())
            ->whereIn('protocol_version', $this->eligibility->supportedProtocolVersions())
            ->where('last_seen_at', '>=', now()->subSeconds($offlineAfter))
            ->withCount([
                // Deployments not finished yet will start containers the
                // last heartbeat does not show (rankByCapacity()).
                'commands as '.self::RESERVED_DEPLOYMENTS_ATTRIBUTE => function ($query): void {
                    $query->where('type', AgentCommandType::DeployProject)->whereIn('status', [
                        AgentCommandStatus::Pending,
                        AgentCommandStatus::Claimed,
                        AgentCommandStatus::Running,
                    ]);
                },
                // Assigned-but-unclaimed commands are reservations too;
                // counting only claimed work would let one node absorb a
                // whole assignment batch.
                'commands as reserved_commands_count' => function ($query): void {
                    $query->whereIn('status', [
                        AgentCommandStatus::Pending,
                        AgentCommandStatus::Claimed,
                        AgentCommandStatus::Running,
                    ]);
                },
            ])
            ->get();

        return $nodes->filter(
            fn (AgentNode $node): bool => $this->eligibility->nodeIsEligibleFor($node, $type),
        )->values();
    }
}
