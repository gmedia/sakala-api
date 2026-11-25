<?php

declare(strict_types=1);

namespace App\Services\CustomDomain;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Services\Agent\AgentCommandPayloadMaterializer;
use App\Services\Runtime\ProjectWorkloadService;
use App\Support\CustomDomain\CustomDomainAudit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tells the node that keeps a project's route which custom domains it serves
 * (ADR-024), with UpdateProjectDomains, whenever the project's verified list
 * changes: a domain verified, reactivated, disabled, or deleted.
 *
 * The command goes to the node of the deployment whose container the node
 * keeps (ProjectWorkloadService::retainedWorkload()), only when that node has
 * the `custom-domains` capability. Without a workload there is no route to
 * change: the next route-writing command carries the list. The list itself
 * is read when the node polls (AgentCommandPayloadMaterializer), so one
 * pending command per project and node is enough, however many changes it
 * covers.
 *
 * Callers may hold a project domain row lock; this locks nothing and only
 * inserts a command, which takes a key-share lock on the node row and never
 * waits on a heartbeat.
 */
final class CustomDomainRouteSync
{
    /** Times a refused update is sent again after `runtime_custom_domain_taken`. */
    public const MAX_TAKEN_RETRIES = 5;

    /** Seconds before the retry: time for the update that frees the name on the other project. */
    public const TAKEN_RETRY_DELAY_SECONDS = 60;

    /** Agent `error_code` when another project's route on the node serves a name. */
    public const ERROR_TAKEN = 'runtime_custom_domain_taken';

    /** Agent `error_code` when a name breaks the agent's rules. */
    public const ERROR_INVALID = 'runtime_custom_domain_invalid';

    /** `request_context.reason`: a domain became verified or was reactivated. */
    public const REASON_VERIFIED = 'domain_verified';

    /** `request_context.reason`: a verified domain was disabled after failing checks. */
    public const REASON_DISABLED = 'domain_disabled';

    /** `request_context.reason`: an admin disabled a verified domain. */
    public const REASON_ADMIN_DISABLED = 'domain_admin_disabled';

    /** `request_context.reason`: a verified domain was deleted. */
    public const REASON_DELETED = 'domain_deleted';

    /** `request_context.reason`: the node refused with `runtime_custom_domain_taken`. */
    public const REASON_RETRY_AFTER_TAKEN = 'retry_after_taken';

    /** `request_context.reason`: the previous update's lease ran out. */
    public const REASON_RETRY_AFTER_EXPIRY = 'retry_after_expiry';

    /** `request_context.attempt` of a retried update. */
    private const ATTEMPT_KEY = 'attempt';

    public function __construct(
        private readonly ProjectWorkloadService $workloads,
    ) {}

    /**
     * Whether a node keeps the project's route but cannot serve custom
     * domains (an agent without the capability): its verified domains then
     * answer 404 until the project runs on a node that can.
     */
    public function waitingForNode(Project $project): bool
    {
        $node = $this->routeNode($project);

        return $node !== null && ! $node->hasCapability(AgentCommandType::CUSTOM_DOMAINS_CAPABILITY);
    }

    /** Sends UpdateProjectDomains for the project when a node keeps its route; null when nothing was sent. */
    public function sync(Project $project, string $reason, int $attempt = 1, int $delaySeconds = 0): ?AgentCommand
    {
        if (! CustomDomainPolicy::enabled()) {
            return null;
        }

        $deployment = $this->workloads->retainedWorkload($project);
        $node = $this->routeNode($project, $deployment);

        if ($deployment === null || $node === null || ! $node->hasCapability(AgentCommandType::CUSTOM_DOMAINS_CAPABILITY)) {
            return null;
        }

        $pending = AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('agent_node_id', $node->id)
            ->where('type', AgentCommandType::UpdateProjectDomains)
            ->where('status', AgentCommandStatus::Pending)
            ->exists();

        if ($pending) {
            return null;
        }

        return AgentCommand::query()->create([
            'project_id' => $project->id,
            'deployment_id' => $deployment->id,
            'agent_node_id' => $node->id,
            'type' => AgentCommandType::UpdateProjectDomains,
            'status' => AgentCommandStatus::Pending,
            // What the list was when the change happened; the node gets the list as it is when it polls.
            'payload' => [AgentCommandPayloadMaterializer::CUSTOM_DOMAINS_KEY => ProjectDomain::activeHostnamesFor($project->id)],
            'request_context' => ['reason' => $reason, self::ATTEMPT_KEY => $attempt],
            'idempotency_key' => "project-domains:{$project->id}:".Str::uuid()->toString(),
            'available_at' => now()->addSeconds($delaySeconds),
        ]);
    }

    /** The node that keeps the project's route: the one holding its retained workload. */
    private function routeNode(Project $project, ?Deployment $deployment = null): ?AgentNode
    {
        $deployment ??= $this->workloads->retainedWorkload($project);

        return $deployment?->agent_node_id === null ? null : AgentNode::query()->find($deployment->agent_node_id);
    }

    /**
     * The node refused UpdateProjectDomains. `runtime_custom_domain_taken`
     * usually means the update that frees the name on another project of
     * the node has not run yet, so the update is sent again a minute later,
     * up to MAX_TAKEN_RETRIES times. Anything else is a bug between the API
     * and the agent rules: it is logged for the operator.
     */
    public function failed(AgentNode $agent, AgentCommand $command, string $errorCode): void
    {
        $project = $command->project_id === null ? null : Project::query()->find($command->project_id);

        if ($project === null) {
            return;
        }

        $attempt = (int) ($command->request_context[self::ATTEMPT_KEY] ?? 1);

        foreach (ProjectDomain::query()->verified()->where('project_id', $project->id)->get() as $domain) {
            CustomDomainAudit::record($agent, CustomDomainAudit::ROUTE_UPDATE_FAILED, $domain, [
                'command_id' => $command->id,
                'error_code' => $errorCode,
                'attempt' => $attempt,
            ]);
        }

        if ($errorCode === self::ERROR_TAKEN && $attempt <= self::MAX_TAKEN_RETRIES) {
            $this->sync($project, self::REASON_RETRY_AFTER_TAKEN, $attempt + 1, self::TAKEN_RETRY_DELAY_SECONDS);

            return;
        }

        Log::error('A node refused the custom domains of a project.', [
            'project_id' => $project->id,
            'command_id' => $command->id,
            'agent_node_id' => $agent->id,
            'error_code' => $errorCode,
            'error_message' => $command->error_message,
        ]);
    }
}
