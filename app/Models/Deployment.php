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
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property int|null $requested_by
 * @property string|null $agent_node_id
 * @property int $sequence
 * @property DeploymentStatus $status
 * @property DeploymentTrigger $trigger
 * @property string $branch
 * @property string $commit_sha
 * @property string|null $commit_message
 * @property string|null $image_reference
 * @property string|null $failure_code
 * @property string|null $failure_summary
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'project_id',
    'requested_by',
    'agent_node_id',
    'idempotency_key',
    'sequence',
    'status',
    'realtime_sequence',
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
            'realtime_sequence' => 'integer',
            'trigger' => DeploymentTrigger::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
