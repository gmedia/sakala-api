<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Str;

final class CreateSleepProjectCommandAction
{
    /**
     * @param  array<string, mixed>  $responseContext
     */
    public function handle(
        Deployment $deployment,
        ?User $user = null,
        ?string $reason = null,
        ?string $idempotencyKey = null,
        ?array $responseContext = null,
    ): AgentCommand {
        if ($deployment->agent_node_id === null) {
            throw new \RuntimeException(
                'Deployment must have an agent node before creating a sleep command.',
            );
        }

        return AgentCommand::create([
            'project_id' => $deployment->project_id,
            'deployment_id' => $deployment->id,
            'agent_node_id' => $deployment->agent_node_id,
            'type' => AgentCommandType::SleepProject,
            'status' => AgentCommandStatus::Pending,
            'payload' => [],

            'request_context' => [
                'reason' => $reason,
                'actor_type' => $user !== null ? User::class : null,
                'actor_id' => $user?->id !== null
                    ? (string) $user->id
                    : null,
            ],

            'response_context' => $responseContext,

            'idempotency_key' => $idempotencyKey
                ?? Str::uuid()->toString(),

            'available_at' => now(),
        ]);
    }
}
