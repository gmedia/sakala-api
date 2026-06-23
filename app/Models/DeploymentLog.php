<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LogStream;
use Database\Factories\DeploymentLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'deployment_id',
    'agent_command_id',
    'sequence',
    'stream',
    'message',
    'recorded_at',
])]
class DeploymentLog extends Model
{
    /** @use HasFactory<DeploymentLogFactory> */
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
            'stream' => LogStream::class,
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
