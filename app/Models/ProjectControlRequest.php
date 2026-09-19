<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectControlAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $project_id
 * @property ProjectControlAction $action
 * @property string $idempotency_key
 * @property string $actor_type
 * @property int|string $actor_id
 * @property string $reason
 * @property string|null $agent_command_id
 * @property array<string, mixed>|null $response_context
 */
class ProjectControlRequest extends Model
{
    protected $fillable = [
        'project_id',
        'action',
        'idempotency_key',
        'actor_type',
        'actor_id',
        'reason',
        'agent_command_id',
        'response_context',
    ];

    protected function casts(): array
    {
        return [
            'action' => ProjectControlAction::class,
            'response_context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<AgentCommand, $this>
     */
    public function agentCommand(): BelongsTo
    {
        return $this->belongsTo(AgentCommand::class);
    }
}
