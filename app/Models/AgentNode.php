<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
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
     * Key under the heartbeat's `metadata.workloads` where an agent with
     * REPLICAS_CAPABILITY reports the most replicas it runs per project
     * (`SAKALA_MAX_REPLICAS_PER_PROJECT`), or null when its driver cannot run
     * replicas.
     */
    public const MAXIMUM_REPLICAS_METADATA_KEY = 'maximum_replicas_per_project';

    /** Upper bound of that report, the agent's own ceiling. */
    public const MAXIMUM_REPLICAS_REPORT_CEILING = 64;

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
