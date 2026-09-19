<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentCommandStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Services\Agent\AgentCommandEligibilityService;
use App\Services\Agent\ProjectCommandEligibilityService;
use Illuminate\Support\Collection;

final class PollAgentCommandsAction
{
    public function __construct(
        private readonly AgentCommandEligibilityService $eligibility,
        private readonly ProjectCommandEligibilityService $projectEligibility,
    ) {}

    /**
     * Return pending, non-expired commands eligible for the given agent node.
     * Eligibility: available_at <= now, expires_at not passed, the command is
     * scoped to the node (assigned to it, or unassigned and not of a pinned
     * type), and the node may execute the command type given its protocol
     * revision, authorisation, reported status, desired lifecycle state, and
     * capabilities. All filters are applied in SQL before the batch limit so
     * the limit truly means "maximum N eligible commands". Nodes that are
     * draining, drained, or in maintenance only see their own DrainNode and
     * ResumeNode commands; offline or unsupported nodes see nothing.
     *
     * @return Collection<int, AgentCommand>
     */
    public function handle(AgentNode $agent): Collection
    {
        $eligibleTypes = $this->eligibility->eligibleTypeValues($agent);

        if ($eligibleTypes === []) {
            return collect();
        }

        $query = AgentCommand::query()
            ->where('status', AgentCommandStatus::Pending)
            ->whereIn('type', $eligibleTypes)
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });

        $query = $this->eligibility->applyNodeScopeToQuery($query, $agent);

        /** @var \Illuminate\Database\Eloquent\Collection<int, AgentCommand> $commands */
        $commands = $this->projectEligibility
            ->applyToQuery($query)
            ->orderBy('available_at', 'asc')
            ->orderBy('created_at', 'asc')
            ->limit(config('sakala.agent.command_batch_size', 10))
            ->get();

        return $commands;
    }
}
