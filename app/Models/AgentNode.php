<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Agent\NodeCapacityData;
use App\Data\Database\DatabaseEndpointData;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Rules\EdgeAddress;
use App\Rules\NetworkHost;
use App\Rules\RegionSlug;
use Carbon\CarbonImmutable;
use Database\Factories\AgentNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property AgentAuthStatus $auth_status
 * @property AgentNodeStatus $status
 * @property AgentNodeDesiredState $desired_state
 * @property int|null $protocol_version
 * @property CarbonImmutable|null $registered_at
 * @property CarbonImmutable|null $last_seen_at
 * @property array<int, string>|null $capabilities
 * @property array<string, mixed>|null $metadata The last heartbeat's metadata, as reported.
 * @property string|null $region_override Region an admin set; wins over the reported one (effectiveRegion()).
 * @property string|null $edge_address_override Edge address an admin set; wins over the reported one (effectiveEdgeAddress()).
 */
#[Fillable([
    'agent_id',
    'name',
    'token_hash',
    'token_prefix',
    'status',
    'auth_status',
    'protocol_version',
    'desired_state',
    'description',
    'hostname',
    'runtime_network',
    'region_override',
    'edge_address_override',
    'capabilities',
    'metadata',
    'registered_at',
    'last_seen_at',
])]
#[Hidden(['token_hash'])]
class AgentNode extends Model
{
    /** @use HasFactory<AgentNodeFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::creating(function (AgentNode $node): void {
            if (! isset($node->attributes['status'])) {
                $node->status = AgentNodeStatus::Offline;
            }

            if (! isset($node->attributes['desired_state'])) {
                $node->desired_state = AgentNodeDesiredState::Active;
            }
        });
    }

    /**
     * Capability of a node that runs a deployment's `replicas` behind its
     * router. Agents without it ignore the payload field and run one container.
     */
    public const REPLICAS_CAPABILITY = 'replicas';

    /**
     * Capability of a node that runs a project's `release_command` once per
     * deployment before the new version takes traffic. Agents without it would
     * ignore the payload field and skip the command, so such a deployment is
     * only placed on nodes that advertise it.
     */
    public const RELEASE_COMMAND_CAPABILITY = 'release-command';

    /**
     * Key under the heartbeat's `metadata.workloads` where an agent with
     * REPLICAS_CAPABILITY reports the most replicas it runs per project
     * (`SAKALA_MAX_REPLICAS_PER_PROJECT`), or null when its driver cannot run
     * replicas.
     */
    public const MAXIMUM_REPLICAS_METADATA_KEY = 'maximum_replicas_per_project';

    /** Upper bound of that report, the agent's own ceiling. */
    public const MAXIMUM_REPLICAS_REPORT_CEILING = 64;

    /**
     * Heartbeat `metadata.runtime`: where the node is and how Sakala Edge
     * reaches it. Both keys are optional and nullable (additive in protocol
     * revision 4).
     */
    public const RUNTIME_METADATA_KEY = 'runtime';

    /** `metadata.runtime.region`, from `SAKALA_AGENT_REGION` (App\Rules\RegionSlug). */
    public const REGION_METADATA_KEY = 'region';

    /** `metadata.runtime.edge_address`, the node router's `host:port` as the Edge reaches it (App\Rules\EdgeAddress). */
    public const EDGE_ADDRESS_METADATA_KEY = 'edge_address';

    /** `metadata.workloads.active`: containers the node runs now. */
    public const ACTIVE_WORKLOADS_METADATA_KEY = 'active';

    /**
     * `metadata.workloads.maximum_active_workloads`: the most containers the
     * node runs at once (`SAKALA_MAX_ACTIVE_CONTAINERS`), optional and
     * nullable (additive in protocol revision 4).
     */
    public const MAXIMUM_ACTIVE_WORKLOADS_METADATA_KEY = 'maximum_active_workloads';

    /**
     * Heartbeat `metadata.database`, sent by nodes with the `managed-postgres`
     * capability (additive in protocol revision 4).
     */
    public const DATABASE_METADATA_KEY = 'database';

    /** `metadata.database.endpoint`: `{host, port}` where attached projects reach the instance. */
    public const DATABASE_ENDPOINT_METADATA_KEY = 'endpoint';

    /** `metadata.database.available`: whether the agent's last size reading of the instance worked. */
    public const DATABASE_AVAILABLE_METADATA_KEY = 'available';

    /** Highest TCP port. */
    public const MAX_PORT = 65535;

    /** `metadata.resources` keys the scheduler reads. */
    public const MEMORY_TOTAL_METADATA_KEY = 'memory_total_bytes';

    public const MEMORY_AVAILABLE_METADATA_KEY = 'memory_available_bytes';

    /**
     * The region the node serves: the admin override, else what its last
     * heartbeat reported, else null (a node without a region only takes
     * projects that ask for none).
     */
    public function effectiveRegion(): ?string
    {
        return $this->region_override ?? $this->reportedRegion();
    }

    /** The region the last heartbeat reported in `metadata.runtime.region`. */
    public function reportedRegion(): ?string
    {
        $region = $this->runtimeMetadata()[self::REGION_METADATA_KEY] ?? null;

        return RegionSlug::isValid($region) ? $region : null;
    }

    /**
     * Where Sakala Edge reaches the node's runtime router (`host:port`): the
     * admin override, else what its last heartbeat reported, else null, in
     * which case the Edge route table skips the node's projects.
     */
    public function effectiveEdgeAddress(): ?string
    {
        return $this->edge_address_override ?? $this->reportedEdgeAddress();
    }

    /** The address the last heartbeat reported in `metadata.runtime.edge_address`. */
    public function reportedEdgeAddress(): ?string
    {
        $address = $this->runtimeMetadata()[self::EDGE_ADDRESS_METADATA_KEY] ?? null;

        return EdgeAddress::isValid($address) ? $address : null;
    }

    /**
     * Where attached projects reach this node's PostgreSQL instance, from its
     * last heartbeat; null when it reported none or a malformed one.
     */
    public function databaseEndpoint(): ?DatabaseEndpointData
    {
        $database = $this->metadata[self::DATABASE_METADATA_KEY] ?? null;
        $endpoint = is_array($database) ? ($database[self::DATABASE_ENDPOINT_METADATA_KEY] ?? null) : null;

        if (! is_array($endpoint)) {
            return null;
        }

        $host = $endpoint['host'] ?? null;
        $port = $endpoint['port'] ?? null;

        if (! NetworkHost::isValid($host) || ! is_int($port) || $port < 1 || $port > self::MAX_PORT) {
            return null;
        }

        /** @var string $host */
        return new DatabaseEndpointData($host, $port);
    }

    /**
     * Whether the node's PostgreSQL instance answered its agent at the last
     * reading (`metadata.database.available`). A node that does not report it
     * counts as available.
     */
    public function databaseAvailable(): bool
    {
        $database = $this->metadata[self::DATABASE_METADATA_KEY] ?? null;

        return ! is_array($database) || ($database[self::DATABASE_AVAILABLE_METADATA_KEY] ?? true) !== false;
    }

    /** Free memory and container slots from the last heartbeat. */
    public function capacity(): NodeCapacityData
    {
        $resources = $this->metadata['resources'] ?? null;
        $resources = is_array($resources) ? $resources : [];
        $workloads = $this->workloadMetadata();
        $integer = static fn (mixed $value): ?int => is_int($value) && $value >= 0 ? $value : null;

        return new NodeCapacityData(
            memoryTotalBytes: $integer($resources[self::MEMORY_TOTAL_METADATA_KEY] ?? null),
            memoryAvailableBytes: $integer($resources[self::MEMORY_AVAILABLE_METADATA_KEY] ?? null),
            activeWorkloads: $integer($workloads[self::ACTIVE_WORKLOADS_METADATA_KEY] ?? null),
            maximumActiveWorkloads: $integer($workloads[self::MAXIMUM_ACTIVE_WORKLOADS_METADATA_KEY] ?? null),
        );
    }

    /** @return array<string, mixed> */
    private function runtimeMetadata(): array
    {
        $runtime = $this->metadata[self::RUNTIME_METADATA_KEY] ?? null;

        return is_array($runtime) ? $runtime : [];
    }

    /**
     * Whether a deployment of `$replicas` containers may be sent to this node:
     * one always; more only when it advertises REPLICAS_CAPABILITY and its
     * reported maximum allows that many. A capable node that does not report a
     * maximum is trusted with any count.
     */
    public function supportsReplicas(int $replicas): bool
    {
        if ($replicas <= Project::DEFAULT_REPLICAS) {
            return true;
        }

        if (! $this->hasCapability(self::REPLICAS_CAPABILITY)) {
            return false;
        }

        if (! $this->reportsMaximumReplicas()) {
            return true;
        }

        $maximum = $this->maximumReplicasPerProject();

        return $maximum !== null && $maximum >= $replicas;
    }

    /**
     * The most replicas per project the node's last heartbeat reported. Null
     * when it reported that it cannot run replicas, when it does not report a
     * maximum at all (see reportsMaximumReplicas()), and for a node without
     * REPLICAS_CAPABILITY.
     */
    public function maximumReplicasPerProject(): ?int
    {
        if (! $this->hasCapability(self::REPLICAS_CAPABILITY)) {
            return null;
        }

        $maximum = $this->workloadMetadata()[self::MAXIMUM_REPLICAS_METADATA_KEY] ?? null;

        return is_int($maximum) ? $maximum : null;
    }

    /** Whether the node's last heartbeat carried a replica maximum, null included. */
    private function reportsMaximumReplicas(): bool
    {
        return array_key_exists(self::MAXIMUM_REPLICAS_METADATA_KEY, $this->workloadMetadata());
    }

    /** @return array<string, mixed> */
    private function workloadMetadata(): array
    {
        $workloads = $this->metadata['workloads'] ?? null;

        return is_array($workloads) ? $workloads : [];
    }

    /**
     * Whether a deployment may be sent to this node: one with `$replicas`
     * containers (supportsReplicas()) and, when `$releaseCommand`, a release
     * command to run first (RELEASE_COMMAND_CAPABILITY).
     */
    public function canRunDeployment(int $replicas, bool $releaseCommand): bool
    {
        return $this->supportsReplicas($replicas)
            && (! $releaseCommand || $this->hasCapability(self::RELEASE_COMMAND_CAPABILITY));
    }

    /** Whether the node's last heartbeat advertised `$capability`. */
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? [], true);
    }

    /** @return HasMany<Deployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /** @return HasMany<AgentCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(AgentCommand::class);
    }

    /**
     * Managed databases hosted on the node, not counting dropped ones.
     *
     * @return HasMany<ManagedDatabase, $this>
     */
    public function databases(): HasMany
    {
        return $this->hasMany(ManagedDatabase::class, 'node_id');
    }

    /** @return HasMany<AgentNodeControlRequest, $this> */
    public function controlRequests(): HasMany
    {
        return $this->hasMany(AgentNodeControlRequest::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AgentNodeStatus::class,
            'auth_status' => AgentAuthStatus::class,
            'protocol_version' => 'integer',
            'desired_state' => AgentNodeDesiredState::class,
            'capabilities' => 'array',
            'metadata' => 'array',
            'registered_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
