<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Data\Agent\ReplicaPlacementData;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\BucketStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ValkeyStatus;
use App\Exceptions\Bucket\BucketCapacityUnavailableException;
use App\Exceptions\Bucket\BucketRegionUnavailableException;
use App\Exceptions\Database\DatabaseRegionUnavailableException;
use App\Exceptions\Runtime\ProjectRegionUnavailableException;
use App\Exceptions\Runtime\ProjectReleaseCommandUnsupportedException;
use App\Exceptions\Runtime\ProjectReplicasUnsupportedException;
use App\Exceptions\Runtime\ProjectWorkerProcessesUnsupportedException;
use App\Exceptions\Valkey\ValkeyCapacityUnavailableException;
use App\Exceptions\Valkey\ValkeyUnavailableException;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\ManagedBucket;
use App\Models\ManagedDatabase;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\Database\RowLock;
use App\Support\Runtime\RuntimeFeatures;
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
        bool $workerProcesses = false,
        ?string $requiredNodeId = null,
    ): ?AgentNode {
        return $this->placementFor($type, $project, $replicas, $releaseCommand, $workerProcesses, $requiredNodeId)->primaryNode();
    }

    /**
     * Where a command of the given type for the project runs, in this order:
     *
     * 1. Only eligible nodes (eligibleNodes()) that run every replica and,
     *    for a deployment with a release command or worker processes, run
     *    those too (AgentNode::canRunDeployment()).
     * 2. A project already served by a node stays there, whatever its region
     *    and capacity, or waits for it (see below).
     * 3. Otherwise only nodes of the project's region
     *    (PilotRuntimeLimitService::regionFor()), when it has one.
     * 4. Among those, the node with the most free capacity (rankByCapacity()).
     *
     * With `$requiredNodeId` (the node of the project's attached Valkey
     * store, ADR-023) only that node, and only while it advertises
     * `managed-valkey`, is a candidate, whatever its region: the store's
     * network exists only there. A project already served by another node
     * gets none (CreateDeploymentAction refuses that case first).
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
        bool $workerProcesses = false,
        ?string $requiredNodeId = null,
    ): ReplicaPlacementData {
        $candidates = $this->eligibleNodes($type)
            ->filter(static fn (AgentNode $node): bool => $node->canRunDeployment($replicas, $releaseCommand, $workerProcesses))
            ->when($requiredNodeId !== null, static fn (Collection $nodes): Collection => $nodes->filter(
                static fn (AgentNode $node): bool => $node->id === $requiredNodeId && $node->hasCapability(AgentCommandType::MANAGED_VALKEY_CAPABILITY),
            ))
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

            if ($requiredNodeId === null) {
                $candidates = $this->inRegion($candidates, $this->limits->regionFor($project));
            }
        }

        $node = $this->rankByCapacity($candidates, $replicas)->first();

        return $node === null ? ReplicaPlacementData::none() : ReplicaPlacementData::onOneNode($node, $replicas);
    }

    /**
     * Refuse a deployment that no node could ever run, so it is not queued
     * forever. Nothing to check when a node was selected. Otherwise the node
     * the project is pinned to (or its Valkey store's node, `$requiredNodeId`),
     * whatever its state, or else every node of the
     * project's region that is allowed work, must exist and run all
     * `$replicas` and, when `$releaseCommand` or `$workerProcesses`, the
     * release command or the worker processes. A pinned or known node that
     * only needs to come back online is fine; the deployment waits for it.
     *
     * @throws ProjectRegionUnavailableException
     * @throws ProjectReplicasUnsupportedException
     * @throws ProjectReleaseCommandUnsupportedException
     * @throws ProjectWorkerProcessesUnsupportedException
     */
    public function ensurePlaceable(Project $project, ?AgentNode $selected, int $replicas, bool $releaseCommand = false, bool $workerProcesses = false, ?string $requiredNodeId = null): void
    {
        if ($selected !== null) {
            return;
        }

        // A project with a Valkey store is bound to the store's node, like a
        // project already served by a node (CreateDeploymentAction checked
        // that the two agree).
        $currentNodeId = $requiredNodeId ?? $this->currentNodeIdOf($project);
        $region = $currentNodeId === null ? $this->limits->regionFor($project) : null;

        if ($region === null && $replicas <= Project::DEFAULT_REPLICAS && ! $releaseCommand && ! $workerProcesses) {
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

        if ($nodes->contains(static fn (AgentNode $node): bool => $node->canRunDeployment($replicas, $releaseCommand, $workerProcesses))) {
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

        // Then the release command, and last the workers.
        if ($releaseCommand
            && ! $nodes->contains(static fn (AgentNode $node): bool => $node->canRunDeployment($replicas, true))) {
            throw new ProjectReleaseCommandUnsupportedException;
        }

        throw new ProjectWorkerProcessesUnsupportedException;
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
     * The ranking reads without locks; the caller then locks the chosen node
     * (lockBucketNode()) and checks again (ensureBucketNodeFits()), so two
     * creates in different workspaces cannot both take its last MB. The agent
     * still rechecks its real total quota and disk.
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
     * Lock the storage node row, so placements and quota increases on one
     * node are counted one at a time across workspaces. Order: workspace,
     * then node, then project or bucket; the heartbeat locks the node before
     * it writes bucket rows, so this order cannot deadlock with it.
     */
    public function lockBucketNode(string $nodeId): AgentNode
    {
        /** @var AgentNode */
        return AgentNode::query()->whereKey($nodeId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Whether a locked storage node still has room for `$quotaMb`: its
     * reported capacity minus every live, non-failed bucket's reservation on
     * it, without `$exceptBucketId` (a bucket being resized).
     *
     * @throws BucketCapacityUnavailableException
     */
    public function ensureBucketNodeFits(AgentNode $lockedNode, int $quotaMb, ?string $exceptBucketId = null): void
    {
        $capacity = $lockedNode->objectStorage()?->capacityMb;

        if ($capacity === null || $capacity - ManagedBucket::reservedMbOnNode($lockedNode->id, except: $exceptBucketId) < $quotaMb) {
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
     * The Valkey node a new store goes to (ADR-023): a node with the
     * `managed-valkey` capability that reported its role
     * (`metadata.valkey`) and whose budget (`capacity_mb` minus
     * Σ(maxmemory_mb + 64) of its live stores, ManagedValkey::budgetMbOnNode())
     * fits the new store. With a project that already runs on a node, only
     * that node: a store is reachable only from its own node. Otherwise only
     * nodes of `$region`, or any region when it is null. Online eligible nodes
     * whose role answered come first, then the most free budget, then the
     * freshest heartbeat; when no online node has room, an allowed node that
     * is offline and has room is chosen, and the store waits for it. A store
     * never moves, so the choice is final.
     *
     * The ranking reads without locks; the caller then locks the chosen node
     * (lockValkeyNode()) and checks again (ensureValkeyNodeFits()). The agent
     * still rechecks its own budget and network pool.
     *
     * @throws ValkeyUnavailableException when no node may ever host it.
     * @throws ValkeyCapacityUnavailableException when nodes exist but none has room.
     */
    public function selectValkeyNode(?string $region, int $sizeMb, ?Project $project = null): AgentNode
    {
        $currentNodeId = $project === null ? null : $this->currentNodeIdOf($project);
        $reported = static fn (AgentNode $node): bool => $node->valkeyHost() !== null;

        $online = $this->eligibleNodes(AgentCommandType::CreateValkey)->filter($reported);

        /** @var Collection<int, AgentNode> $allowed */
        $allowed = AgentNode::query()
            ->where('auth_status', AgentAuthStatus::Active)
            ->where('desired_state', AgentNodeDesiredState::Active)
            ->whereIn('protocol_version', $this->eligibility->supportedProtocolVersions())
            ->get()
            ->filter(static fn (AgentNode $node): bool => $node->hasCapability(AgentCommandType::MANAGED_VALKEY_CAPABILITY))
            ->filter($reported);

        if ($currentNodeId !== null) {
            $online = $online->where('id', $currentNodeId);
            $allowed = $allowed->where('id', $currentNodeId);
        } else {
            $online = $this->inRegion($online, $region);
            $allowed = $this->inRegion($allowed, $region);
        }

        if ($online->isEmpty() && $allowed->isEmpty()) {
            throw new ValkeyUnavailableException;
        }

        $free = $this->freeValkeyBudget($online->merge($allowed));
        $fits = static fn (AgentNode $node): bool => ($free[$node->id] ?? -1) >= $sizeMb + ManagedValkey::NODE_OVERHEAD_MB;

        $node = $this->rankByFreeValkeyBudget($online->filter($fits), $free)->first()
            ?? $this->rankByFreeValkeyBudget($allowed->filter($fits), $free)->first();

        if ($node === null) {
            throw new ValkeyCapacityUnavailableException;
        }

        return $node;
    }

    /**
     * Lock the Valkey node row, so placements and growths on one node are
     * counted one at a time across workspaces. FOR NO KEY UPDATE, like the
     * heartbeat: a command insert for this node checks its foreign key
     * without waiting. Order: workspace, then node, then store, then project.
     */
    public function lockValkeyNode(string $nodeId): AgentNode
    {
        /** @var AgentNode */
        return AgentNode::query()->whereKey($nodeId)->lock(RowLock::NO_KEY_UPDATE)->firstOrFail();
    }

    /**
     * Whether a locked Valkey node still has room for a store of `$sizeMb`:
     * its reported budget minus what its other live, non-failed stores take,
     * without `$exceptValkeyId` (a store being resized), the agent's formula.
     *
     * @throws ValkeyCapacityUnavailableException
     */
    public function ensureValkeyNodeFits(AgentNode $lockedNode, int $sizeMb, ?string $exceptValkeyId = null): void
    {
        $capacity = $lockedNode->valkeyHost()?->capacityMb;

        if ($capacity === null
            || $capacity - ManagedValkey::budgetMbOnNode($lockedNode->id, except: $exceptValkeyId) < $sizeMb + ManagedValkey::NODE_OVERHEAD_MB) {
            throw new ValkeyCapacityUnavailableException;
        }
    }

    /**
     * Whether this installation offers managed Valkey stores right now:
     * SAKALA_VALKEY_ENABLED is on, and an online node that may receive
     * CreateValkey reported its role and did not report it down.
     */
    public function valkeysAvailable(): bool
    {
        return RuntimeFeatures::valkeyEnabled()
            && $this->eligibleNodes(AgentCommandType::CreateValkey)
                ->contains(static fn (AgentNode $node): bool => $node->valkeyHost()?->available === true);
    }

    /**
     * Whether the project may run on `$nodeId`, the node of a Valkey store:
     * it already runs there, or it runs nowhere yet and has no region of its
     * own or the node's region.
     */
    public function projectMayRunOn(Project $project, string $nodeId): bool
    {
        $currentNodeId = $this->currentNodeIdOf($project);

        if ($currentNodeId !== null) {
            return $currentNodeId === $nodeId;
        }

        $region = $this->limits->regionFor($project);

        return $region === null || AgentNode::query()->find($nodeId)?->effectiveRegion() === $region;
    }

    /**
     * Nodes whose Valkey role answered first, then the most free budget, then
     * the freshest heartbeat.
     *
     * @param  Collection<int, AgentNode>  $nodes
     * @param  array<string, int>  $free
     * @return Collection<int, AgentNode>
     */
    private function rankByFreeValkeyBudget(Collection $nodes, array $free): Collection
    {
        return $nodes
            ->sortBy([
                static fn (AgentNode $a, AgentNode $b): int => ($b->valkeyHost()?->available === true) <=> ($a->valkeyHost()?->available === true),
                static fn (AgentNode $a, AgentNode $b): int => ($free[$b->id] ?? -1) <=> ($free[$a->id] ?? -1),
                static fn (AgentNode $a, AgentNode $b): int => ($b->last_seen_at?->getTimestamp() ?? 0) <=> ($a->last_seen_at?->getTimestamp() ?? 0),
            ])
            ->values();
    }

    /**
     * MB of its Valkey budget each node may still allocate: reported
     * `capacity_mb` minus Σ(reserved maxmemory + 64) of its live, non-failed
     * stores. A node that has not reported its role is absent. Two queries,
     * whatever the number of nodes.
     *
     * @param  Collection<int, AgentNode>  $nodes
     * @return array<string, int>
     */
    private function freeValkeyBudget(Collection $nodes): array
    {
        $rows = ManagedValkey::query()
            ->whereIn('node_id', $nodes->pluck('id')->unique()->all())
            ->where('status', '!=', ValkeyStatus::Failed)
            ->groupBy('node_id')
            ->selectRaw('node_id, sum(reserved_maxmemory_mb) as reserved, count(*) as stores')
            ->get();

        $taken = [];

        foreach ($rows as $row) {
            $taken[(string) $row->getAttribute('node_id')] = (int) $row->getAttribute('reserved') + (int) $row->getAttribute('stores') * ManagedValkey::NODE_OVERHEAD_MB;
        }

        $free = [];

        foreach ($nodes as $node) {
            $capacity = $node->valkeyHost()?->capacityMb;

            if ($capacity !== null) {
                $free[$node->id] = $capacity - ($taken[$node->id] ?? 0);
            }
        }

        return $free;
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
            ->where('status', '!=', BucketStatus::Failed)
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
