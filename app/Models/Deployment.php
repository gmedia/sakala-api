<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id',
    'requested_by',
    'agent_node_id',
    'sequence',
    'status',
    'trigger',
    'branch',
    'commit_sha',
    'commit_message',
    'image_reference',
    'failure_code',
    'failure_summary',
    'started_at',
    'finished_at',
    'cancelled_at',
])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory, HasUuids;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<AgentNode, $this> */
    public function agentNode(): BelongsTo
    {
        return $this->belongsTo(AgentNode::class);
    }

    /** @return HasMany<AgentCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(AgentCommand::class);
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
            'sequence' => 'integer',
            'status' => DeploymentStatus::class,
            'trigger' => DeploymentTrigger::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
