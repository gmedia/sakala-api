<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentNodeControlAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $agent_node_id
 * @property AgentNodeControlAction $action
 * @property string $idempotency_key
 * @property string $actor_type
 * @property int $actor_id
 * @property string $reason
 * @property string|null $agent_command_id
 * @property array<string, mixed>|null $response_context
 */
#[Fillable([
    'agent_node_id',
    'action',
    'idempotency_key',
    'actor_type',
    'actor_id',
    'reason',
    'agent_command_id',
    'response_context',
])]
class AgentNodeControlRequest extends Model
{
    /** @return BelongsTo<AgentNode, $this> */
    public function agentNode(): BelongsTo
    {
        return $this->belongsTo(AgentNode::class);
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
            'action' => AgentNodeControlAction::class,
            'actor_id' => 'integer',
            'response_context' => 'array',
        ];
    }
}
