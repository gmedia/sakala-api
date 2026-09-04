<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeStatus;
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
 * @property array<int, string>|null $capabilities
 */
#[Fillable([
    'agent_id',
    'name',
    'token_hash',
    'token_prefix',
    'status',
    'auth_status',
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AgentNodeStatus::class,
            'auth_status' => AgentAuthStatus::class,
            'capabilities' => 'array',
            'metadata' => 'array',
            'registered_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
