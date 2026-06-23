<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeploymentEventLevel;
use Database\Factories\DeploymentEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'deployment_id',
    'agent_command_id',
    'sequence',
    'level',
    'type',
    'message',
    'metadata',
    'occurred_at',
])]
class DeploymentEvent extends Model
{
    /** @use HasFactory<DeploymentEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return BelongsTo<Deployment, $this> */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /** @return BelongsTo<AgentCommand, $this> */
    public function agentCommand(): BelongsTo
    {
        return $this->belongsTo(AgentCommand::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'level' => DeploymentEventLevel::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
