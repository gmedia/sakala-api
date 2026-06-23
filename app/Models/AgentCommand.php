<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use Database\Factories\AgentCommandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id',
    'deployment_id',
    'agent_node_id',
    'type',
    'status',
    'payload',
    'result',
    'idempotency_key',
    'attempts',
    'error_code',
    'error_message',
    'available_at',
    'claimed_at',
    'started_at',
    'completed_at',
    'failed_at',
    'expires_at',
])]
class AgentCommand extends Model
{
    /** @use HasFactory<AgentCommandFactory> */
    use HasFactory, HasUuids;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Deployment, $this> */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /** @return BelongsTo<AgentNode, $this> */
    public function agentNode(): BelongsTo
    {
        return $this->belongsTo(AgentNode::class);
    }

    /** @return HasMany<DeploymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DeploymentEvent::class);
    }

    /** @return HasMany<DeploymentLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(DeploymentLog::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AgentCommandType::class,
            'status' => AgentCommandStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
