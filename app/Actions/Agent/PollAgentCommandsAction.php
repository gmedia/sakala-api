<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentCommandStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Services\Agent\AgentCommandEligibilityService;
use Illuminate\Support\Collection;

final class PollAgentCommandsAction
{
    public function __construct(
        private readonly AgentCommandEligibilityService $eligibility,
    ) {}

    /**
     * Return pending, non-expired commands eligible for the given agent node.
     * Eligibility: available_at <= now, expires_at not passed, explicit node
     * ownership matches (or command is unowned), and the node holds at least
     * one required capability for the command type. All eligibility filters,
     * including capability matching, are applied in SQL before the batch
     * limit so the limit truly means "maximum N eligible commands". Nodes in
     * draining, drained, maintenance, or offline state receive no workload
     * commands.
     *
     * @return Collection<int, AgentCommand>
     */
    public function handle(AgentNode $agent): Collection
    {
        if (! $this->eligibility->nodeIsCommandEligible($agent)) {
            return collect();
        }

        $eligibleTypes = $this->eligibility->eligibleTypeValues($agent);

        if ($eligibleTypes === []) {
            return collect();
        }

        return AgentCommand::query()
            ->where('status', AgentCommandStatus::Pending)
            ->whereIn('type', $eligibleTypes)
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where(function ($query) use ($agent): void {
                $query->whereNull('agent_node_id')
                    ->orWhere('agent_node_id', $agent->id);
            })
            ->orderBy('available_at', 'asc')
            ->orderBy('created_at', 'asc')
            ->limit(config('sakala.agent.command_batch_size', 10))
            ->get();
    }
}
