<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AgentNodeControlData;
use App\Data\Admin\AgentNodeControlResultData;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeControlAction;
use App\Enums\AgentNodeDesiredState;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AgentNodeControlRequest;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Drain or resume a runtime node. The desired lifecycle state is persisted
 * in the same transaction that creates the DrainNode/ResumeNode command, as
 * the agent contract requires, so `GET /node-state` can never disagree with
 * the command the node is about to receive.
 */
final class ChangeAgentNodeLifecycleAction
{
    public function drain(AgentNode $node, User $user, AgentNodeControlData $data): AgentNodeControlResultData
    {
        return $this->change($node, $user, $data, AgentNodeControlAction::Drain);
    }

    public function resume(AgentNode $node, User $user, AgentNodeControlData $data): AgentNodeControlResultData
    {
        return $this->change($node, $user, $data, AgentNodeControlAction::Resume);
    }

    private function change(
        AgentNode $node,
        User $user,
        AgentNodeControlData $data,
        AgentNodeControlAction $action,
    ): AgentNodeControlResultData {
        return DB::transaction(function () use ($node, $user, $data, $action): AgentNodeControlResultData {
            /** @var AgentNode $locked */
            $locked = AgentNode::query()
                ->whereKey($node->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $this->findExistingRequest($data);

            if ($existing !== null) {
                $this->assertIdempotentRetry($existing, $locked, $user, $action, $data);

                /** @var AgentCommand $command */
                $command = $existing->agentCommand()->firstOrFail();

                return new AgentNodeControlResultData(node: $locked, command: $command);
            }

            if ($locked->auth_status !== AgentAuthStatus::Active) {
                abort(409, 'Agent node is not active.');
            }

            [$type, $desired] = match ($action) {
                AgentNodeControlAction::Drain => [AgentCommandType::DrainNode, AgentNodeDesiredState::Draining],
                AgentNodeControlAction::Resume => [AgentCommandType::ResumeNode, AgentNodeDesiredState::Active],
                AgentNodeControlAction::Cleanup => throw new \LogicException('Cleanup is not a lifecycle change.'),
            };

            if ($this->hasLifecycleCommandInFlight($locked)) {
                abort(409, 'A node lifecycle command is already in progress.');
            }

            if ($locked->desired_state === $desired) {
                abort(409, "Agent node desired state is already {$desired->value}.");
            }

            $previousDesiredState = $locked->desired_state->value;

            $locked->update(['desired_state' => $desired]);

            $command = AgentCommand::create([
                'project_id' => null,
                'deployment_id' => null,
                'agent_node_id' => $locked->id,
                'type' => $type,
                'status' => AgentCommandStatus::Pending,
                'payload' => [],
                'request_context' => [
                    'reason' => $data->reason,
                    'actor_type' => User::class,
                    'actor_id' => (string) $user->id,
                ],
                'response_context' => [
                    'previous_desired_state' => $previousDesiredState,
                ],
                'idempotency_key' => $data->idempotencyKey ?? Str::uuid()->toString(),
                'available_at' => now(),
            ]);

            AgentNodeControlRequest::create([
                'agent_node_id' => $locked->id,
                'action' => $action,
                'idempotency_key' => $command->idempotency_key,
                'actor_type' => User::class,
                'actor_id' => $user->id,
                'reason' => $data->reason,
                'agent_command_id' => $command->id,
                'response_context' => [
                    'desired_state' => $desired->value,
                ],
            ]);

            AuditEvent::create([
                'actor_type' => User::class,
                'actor_id' => (string) $user->id,
                'action' => "agent.node.{$action->value}_requested",
                'subject_type' => AgentNode::class,
                'subject_id' => $locked->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'metadata' => [
                    'reason' => $data->reason,
                    'command_id' => $command->id,
                    'desired_state' => $desired->value,
                ],
            ]);

            return new AgentNodeControlResultData(node: $locked->refresh(), command: $command);
        });
    }

    private function findExistingRequest(AgentNodeControlData $data): ?AgentNodeControlRequest
    {
        if ($data->idempotencyKey === null) {
            return null;
        }

        return AgentNodeControlRequest::query()
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();
    }

    private function assertIdempotentRetry(
        AgentNodeControlRequest $request,
        AgentNode $node,
        User $user,
        AgentNodeControlAction $action,
        AgentNodeControlData $data,
    ): void {
        if (
            $request->agent_node_id !== $node->id
            || $request->action !== $action
            || $request->reason !== $data->reason
            || $request->actor_type !== User::class
            || $request->actor_id !== (int) $user->id
        ) {
            abort(409, 'Idempotency key has already been used for a different request.');
        }
    }

    private function hasLifecycleCommandInFlight(AgentNode $node): bool
    {
        return AgentCommand::query()
            ->where('agent_node_id', $node->id)
            ->whereIn('type', [AgentCommandType::DrainNode, AgentCommandType::ResumeNode])
            ->whereIn('status', [
                AgentCommandStatus::Pending,
                AgentCommandStatus::Claimed,
                AgentCommandStatus::Running,
            ])
            ->exists();
    }
}
