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
