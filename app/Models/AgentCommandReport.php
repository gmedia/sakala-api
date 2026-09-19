<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentCommandReportKind;
use App\Enums\DeploymentEventLevel;
use App\Enums\LogStream;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only event or log reported for a command that has no deployment.
 *
 * @property int $sequence
 * @property AgentCommandReportKind $kind
 * @property DeploymentEventLevel|null $level
 * @property LogStream|null $stream
 * @property string|null $type
 * @property string $message
 * @property array<string, mixed>|null $metadata
 * @property Carbon $occurred_at
 * @property string|null $idempotency_key
 * @property string|null $payload_hash
 */
#[Fillable([
    'agent_command_id',
    'sequence',
    'kind',
    'level',
    'stream',
    'type',
    'message',
    'metadata',
    'occurred_at',
    'idempotency_key',
    'payload_hash',
])]
class AgentCommandReport extends Model
{
    public const UPDATED_AT = null;

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
            'kind' => AgentCommandReportKind::class,
            'level' => DeploymentEventLevel::class,
            'stream' => LogStream::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
