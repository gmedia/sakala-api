<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Deployment;

use App\Enums\DeploymentQueueState;
use App\Enums\DeploymentStatus;
use App\Enums\FinalizationDeferredReason;
use App\Models\Deployment;
use App\Services\Agent\AgentCommandEligibilityService;
use App\Services\Deployment\DeploymentFailureClassifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Deployment
 *
 * @property Deployment $resource
 */
final class DeploymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{
     *     id: string,
     *     project_id: string,
     *     sequence: int,
     *     branch: string,
     *     status: string,
     *     trigger: string,
     *     commit_sha: string|null,
     *     commit_message: string|null,
     *     image_reference: string|null,
     *     requested_resources: array{
     *         memory_mb: int|null,
     *         cpu_millis: int|null,
     *         pids_limit: int|null
     *     }|null,
     *     effective_resources: array{
     *         resources: array{
     *             memory_mb: int,
     *             cpu_millis: int,
     *             pids_limit: int
     *         },
     *         timeouts: array{
     *             build_timeout_seconds: int,
     *             start_timeout_seconds: int,
     *             command_timeout_seconds: int
     *         },
     *         log_bounds: array{
     *             max_line_length: int,
     *             max_batch_lines: int,
     *             max_total_bytes: int
     *         }
     *     }|null,
     *     applied_resources: array{
     *         memory_mb: int|null,
     *         cpu_millis: int|null,
     *         pids_limit: int|null
     *     }|null,
     *     finalization_deferred: bool,
     *     finalization_deferred_reason: FinalizationDeferredReason|null,
     *     agent_node_id: string|null,
     *     is_active: bool,
     *     cancel_requested_at: string|null,
     *     queue_state: string|null,
     *     started_at: string|null,
     *     finished_at: string|null,
     *     cancelled_at: string|null,
     *     failure_code: string|null,
     *     failure_summary: string|null,
     *     failure: array{
     *         code: string,
     *         category: string,
     *         summary: string,
     *         recovery_hint: string,
     *         user_fixable: bool,
     *         detail: string|null,
     *         stage: string|null
     *     }|null,
     *     created_at: string|null,
     *     updated_at: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $failure = $this->resource->failure_code !== null
            ? app(DeploymentFailureClassifier::class)
                ->classify($this->resource->failure_code)
            : null;

        // These are maps: an empty one reads as null, never as a JSON `[]`.
        /**
         * @var array{
         *     memory_mb: int|null,
         *     cpu_millis: int|null,
         *     pids_limit: int|null
         * }|null $requestedResources
         */
        $requestedResources = $this->resource->requested_resources ?: null;

        /**
         * @var array{
         *     resources: array{
         *         memory_mb: int,
         *         cpu_millis: int,
         *         pids_limit: int
         *     },
         *     timeouts: array{
         *         build_timeout_seconds: int,
         *         start_timeout_seconds: int,
         *         command_timeout_seconds: int
         *     },
         *     log_bounds: array{
         *         max_line_length: int,
         *         max_batch_lines: int,
         *         max_total_bytes: int
         *     }
         * }|null $effectiveResources
         */
        $effectiveResources = $this->resource->effective_resources ?: null;

        /**
         * @var array{
         *     memory_mb: int|null,
         *     cpu_millis: int|null,
         *     pids_limit: int|null
         * }|null $appliedResources
         */
        $appliedResources = $this->resource->applied_resources ?: null;

        /** @var string|null $startedAt */
        $startedAt = $this->resource->started_at
            ? (string) $this->resource->started_at->toAtomString()
            : null;

        /** @var string|null $finishedAt */
        $finishedAt = $this->resource->finished_at
            ? (string) $this->resource->finished_at->toAtomString()
            : null;

        /** @var string|null $cancelledAt */
        $cancelledAt = $this->resource->cancelled_at
            ? (string) $this->resource->cancelled_at->toAtomString()
            : null;

        return [
            'id' => $this->resource->id,
            'project_id' => $this->resource->project_id,
            'sequence' => $this->resource->sequence,
            'branch' => $this->resource->branch,
            'status' => $this->resource->status->value,
            'trigger' => $this->resource->trigger->value,
            'commit_sha' => $this->resource->commit_sha,
            'commit_message' => $this->resource->commit_message,
            'image_reference' => $this->resource->image_reference,
            'requested_resources' => $requestedResources,
            'effective_resources' => $effectiveResources,
            'applied_resources' => $appliedResources,
            'finalization_deferred' => $this->resource->finalization_deferred,
            'finalization_deferred_reason' => $this->resource->finalization_deferred_reason,
            'agent_node_id' => $this->resource->agent_node_id,
            'is_active' => $this->resource->project?->active_deployment_id === $this->resource->id,
            /** @var string|null */
            'cancel_requested_at' => $this->resource->cancel_requested_at?->toAtomString(),
            /** @var 'assigned'|'waiting_for_node'|null */
            'queue_state' => $this->queueState()?->value,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'cancelled_at' => $cancelledAt,
            'failure_code' => $this->resource->failure_code,
            'failure_summary' => $this->resource->failure_summary,
            'failure' => $failure === null ? null : [
                'code' => $failure->code,
                'category' => $failure->category->value,
                'summary' => $failure->summary,
                'recovery_hint' => $failure->recoveryHint,
                /** @var bool */
                'user_fixable' => $failure->userFixable,
                /** @var string|null */
                'detail' => $this->resource->failure_detail,
                /** @var 'queued'|'cloning'|'analyzing'|'building'|'deploying'|'routing'|'health_checking'|null */
                'stage' => $this->resource->failure_stage?->value,
            ],
            'created_at' => $this->resource->created_at
                ? $this->resource->created_at->toAtomString()
                : null,
            'updated_at' => $this->resource->updated_at
                ? $this->resource->updated_at->toAtomString()
                : null,
        ];
    }

    /**
     * Why a queued deployment has not started. Only a node that can take
     * workload right now counts: a pinned node that went offline or is
     * draining leaves the deployment waiting all the same.
     */
    private function queueState(): ?DeploymentQueueState
    {
        if ($this->resource->status !== DeploymentStatus::Queued) {
            return null;
        }

        $node = $this->resource->agentNode;

        return $node !== null && app(AgentCommandEligibilityService::class)->nodeAcceptsWorkload($node)
            ? DeploymentQueueState::Assigned
            : DeploymentQueueState::WaitingForNode;
    }
}
