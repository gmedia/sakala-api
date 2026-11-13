<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Str;

final class CreateSleepProjectCommandAction
{
    /**
     * Create a SleepProject pinned to the node that runs the deployment. A
     * suspension passes the admin (or nobody); the idle sweep passes a system
     * actor ID instead, which is how completion tells the two apart. When an
     * idempotency key is given the call is safe to repeat: an existing command
     * with that key is returned untouched.
     *
     * @param  array<string, mixed>  $responseContext
     */
    public function handle(
        Deployment $deployment,
        ?User $user = null,
        ?string $reason = null,
        ?string $idempotencyKey = null,
        ?array $responseContext = null,
        ?string $systemActorId = null,
    ): AgentCommand {
        if ($deployment->agent_node_id === null) {
            throw new \RuntimeException(
                'Deployment must have an agent node before creating a sleep command.',
            );
        }

        if ($idempotencyKey !== null) {
            $existing = AgentCommand::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
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
                'actor_type' => match (true) {
                    $user !== null => User::class,
                    $systemActorId !== null => AuditEvent::SYSTEM_ACTOR_TYPE,
                    default => null,
                },
                'actor_id' => $user?->id !== null
                    ? (string) $user->id
                    : $systemActorId,
            ],

            'response_context' => $responseContext,

            'idempotency_key' => $idempotencyKey
                ?? Str::uuid()->toString(),

            'available_at' => now(),
        ]);
    }
}
