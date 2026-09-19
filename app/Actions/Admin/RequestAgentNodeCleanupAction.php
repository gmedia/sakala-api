<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AgentNodeCleanupData;
use App\Data\Admin\AgentNodeControlResultData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeControlAction;
use App\Enums\RuntimeCleanupTarget;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AgentNodeControlRequest;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Agent\AgentCommandEligibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ask a node to reclaim stale workspaces, images, or routes. The agent
 * refuses CleanupRuntime unless the payload carries `approved: true`; that
 * flag is only ever written here, after an admin asked for it explicitly,
 * and never accepted from a client.
 */
final class RequestAgentNodeCleanupAction
{
    public function __construct(
        private readonly AgentCommandEligibilityService $eligibility,
    ) {}

    public function handle(AgentNode $node, User $user, AgentNodeCleanupData $data): AgentNodeControlResultData
    {
        return DB::transaction(function () use ($node, $user, $data): AgentNodeControlResultData {
            /** @var AgentNode $locked */
            $locked = AgentNode::query()
                ->whereKey($node->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $data->idempotencyKey === null
                ? null
                : AgentNodeControlRequest::query()->where('idempotency_key', $data->idempotencyKey)->first();

            if ($existing !== null) {
                $this->assertIdempotentRetry($existing, $locked, $user, $data);

                /** @var AgentCommand $command */
                $command = $existing->agentCommand()->firstOrFail();

                return new AgentNodeControlResultData(node: $locked, command: $command);
            }

            // The key may already belong to a command created through another
            // flow; refuse deterministically instead of hitting the unique index.
            if ($data->idempotencyKey !== null
                && AgentCommand::query()->where('idempotency_key', $data->idempotencyKey)->exists()) {
                abort(409, 'Idempotency key has already been used for a different request.');
            }

            // Cleanup is destructive; only a node that is active and would
            // actually be offered the command may receive it.
            if (! $this->eligibility->nodeIsEligibleFor($locked, AgentCommandType::CleanupRuntime)) {
                abort(409, 'Agent node is not active and eligible for runtime cleanup.');
            }

            $inFlight = AgentCommand::query()
                ->where('agent_node_id', $locked->id)
                ->where('type', AgentCommandType::CleanupRuntime)
                ->whereIn('status', [AgentCommandStatus::Pending, AgentCommandStatus::Claimed, AgentCommandStatus::Running])
                ->exists();

            if ($inFlight) {
                abort(409, 'A runtime cleanup is already in progress on this node.');
            }

            $targets = array_values(array_unique(array_map(
                static fn (RuntimeCleanupTarget $target): string => $target->value,
                $data->targets,
            )));

            $command = AgentCommand::create([
                'project_id' => null,
                'deployment_id' => null,
                'agent_node_id' => $locked->id,
                'type' => AgentCommandType::CleanupRuntime,
                'status' => AgentCommandStatus::Pending,
                'payload' => [
                    'approved' => true,
                    'targets' => $targets,
                ],
                'request_context' => [
                    'reason' => $data->reason,
                    'actor_type' => User::class,
                    'actor_id' => (string) $user->id,
                ],
                'idempotency_key' => $data->idempotencyKey ?? Str::uuid()->toString(),
                'available_at' => now(),
            ]);

            AgentNodeControlRequest::create([
                'agent_node_id' => $locked->id,
                'action' => AgentNodeControlAction::Cleanup,
                'idempotency_key' => $command->idempotency_key,
                'actor_type' => User::class,
                'actor_id' => $user->id,
                'reason' => $data->reason,
                'agent_command_id' => $command->id,
                'response_context' => ['targets' => $targets],
            ]);

            AuditEvent::create([
                'actor_type' => User::class,
                'actor_id' => (string) $user->id,
                'action' => 'agent.node.cleanup_requested',
                'subject_type' => AgentNode::class,
                'subject_id' => $locked->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'metadata' => [
                    'reason' => $data->reason,
                    'command_id' => $command->id,
                    'targets' => $targets,
                ],
            ]);

            return new AgentNodeControlResultData(node: $locked, command: $command);
        });
    }

    private function assertIdempotentRetry(
        AgentNodeControlRequest $request,
        AgentNode $node,
        User $user,
        AgentNodeCleanupData $data,
    ): void {
        $targets = array_values(array_unique(array_map(
            static fn (RuntimeCleanupTarget $target): string => $target->value,
            $data->targets,
        )));

        if (
            $request->agent_node_id !== $node->id
            || $request->action !== AgentNodeControlAction::Cleanup
            || $request->reason !== $data->reason
            || $request->actor_type !== User::class
            || $request->actor_id !== (int) $user->id
            || ($request->response_context['targets'] ?? null) !== $targets
        ) {
            abort(409, 'Idempotency key has already been used for a different request.');
        }
    }
}
