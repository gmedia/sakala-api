<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Deployment;

use App\Enums\DeploymentActorKind;
use App\Enums\DeploymentBuildFallbackReason;
use App\Enums\DeploymentBuildMode;
use App\Enums\DeploymentQueueState;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\FinalizationDeferredReason;
use App\Models\Deployment;
use App\Services\Agent\AgentCommandEligibilityService;
use App\Services\Deployment\DeploymentFailureClassifier;
use App\Support\Deployment\DeploymentRollbackEligibility;
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
     *     triggered_by: array{
     *         kind: DeploymentActorKind,
     *         name: string|null,
     *         user_id: int|null
     *     },
     *     rollback: array{
     *         target_deployment_id: string|null,
     *         target_sequence: int|null
     *     }|null,
     *     rollback_target: array{
     *         image_expected: bool
     *     }|null,
     *     commit_sha: string|null,
     *     commit_message: string|null,
     *     image_reference: string|null,
     *     build: array{
     *         mode: DeploymentBuildMode,
     *         image_source_deployment_id: string|null,
     *         image_source_sequence: int|null,
     *         fallback_reason: DeploymentBuildFallbackReason|null
     *     },
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
            /**
             * Who asked for the deployment. `user`: the member (`name` is their current name; both null once the
             * account is deleted). `github_push`: the pusher's GitHub name (`name` null for older deployments,
             * `user_id` always null). `system`: Sakala itself, both null.
             *
             * @var array{kind: DeploymentActorKind, name: string|null, user_id: int|null}
             */
            'triggered_by' => $this->triggeredBy(),
            /**
             * Only for `trigger = rollback`: the deployment it went back to. Both values null once that one is deleted.
             */
            'rollback' => $this->rollback(),
            /**
             * Non-null when the project can be rolled back to this deployment now: it succeeded, is not the active one,
             * has a commit, ran the project's current branch, and the project is not suspended. `image_expected` is
             * true when the node is expected to still hold its image, so the rollback builds nothing.
             */
            'rollback_target' => $this->rollbackTarget(),
            'commit_sha' => $this->resource->commit_sha,
            'commit_message' => $this->resource->commit_message,
            'image_reference' => $this->resource->image_reference,
            'build' => $this->build(),
            'requested_resources' => $requestedResources,
            'effective_resources' => $effectiveResources,
            'applied_resources' => $appliedResources,
            'finalization_deferred' => $this->resource->finalization_deferred,
            'finalization_deferred_reason' => $this->resource->finalization_deferred_reason,
            'agent_node_id' => $this->resource->agent_node_id,
            'is_active' => $this->resource->project?->active_deployment_id === $this->resource->id,
            /** @var string|null */
            'cancel_requested_at' => $this->resource->cancel_requested_at?->toAtomString(),
            /** @var 'assigned'|'waiting_for_node'|'waiting_for_inspection'|null */
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
     * How the deployment got its image. `mode` is `reused` while a planned
     * reuse stands (and once confirmed), `built` otherwise, including after a
     * fallback and for every deployment from before image reuse. The source
     * is the deployment that built the image this one asked to reuse, null
     * when reuse was never planned or that deployment was deleted;
     * `fallback_reason` is set only when reuse was planned and the agent built
     * instead. Lists eager-load `imageSource` (GetDeploymentCollectionAction).
     *
     * @return array{
     *     mode: DeploymentBuildMode,
     *     image_source_deployment_id: string|null,
     *     image_source_sequence: int|null,
     *     fallback_reason: DeploymentBuildFallbackReason|null
     * }
     */
    private function build(): array
    {
        $sourceId = $this->resource->image_source_deployment_id;

        return [
            'mode' => $this->resource->build_mode,
            'image_source_deployment_id' => $sourceId,
            'image_source_sequence' => $sourceId === null ? null : $this->resource->imageSource?->sequence,
            'fallback_reason' => $this->resource->build_fallback_reason,
        ];
    }

    /**
     * Who asked for the deployment. `user` (manual, redeploy, rollback) names
     * the member who asked, by their current name, and is null for both once
     * that account is deleted. `github_push` names the pusher from the push
     * payload, null for webhook deployments from before it was recorded; the
     * workspace member a push deploys as is never shown. `system` names
     * nobody. Lists eager-load `requester` (GetDeploymentCollectionAction).
     *
     * @return array{kind: DeploymentActorKind, name: string|null, user_id: int|null}
     */
    private function triggeredBy(): array
    {
        $kind = $this->resource->trigger->actorKind();

        return match ($kind) {
            DeploymentActorKind::User => [
                'kind' => $kind,
                'name' => $this->resource->requested_by === null ? null : $this->resource->requester?->name,
                'user_id' => $this->resource->requested_by,
            ],
            DeploymentActorKind::GithubPush => ['kind' => $kind, 'name' => $this->resource->triggered_by_name, 'user_id' => null],
            DeploymentActorKind::System => ['kind' => $kind, 'name' => null, 'user_id' => null],
        };
    }

    /**
     * The deployment a rollback went back to, only for `trigger = rollback`.
     * Both values are null once that deployment is deleted. Lists eager-load
     * `rollbackTarget`.
     *
     * @return array{target_deployment_id: string|null, target_sequence: int|null}|null
     */
    private function rollback(): ?array
    {
        if ($this->resource->trigger !== DeploymentTrigger::Rollback) {
            return null;
        }

        $targetId = $this->resource->rollback_target_deployment_id;

        return [
            'target_deployment_id' => $targetId,
            'target_sequence' => $targetId === null ? null : $this->resource->rollbackTarget?->sequence,
        ];
    }

    /**
     * Non-null when the project can be rolled back to this deployment now
     * (DeploymentRollbackEligibility::isTarget()); `image_expected` says
     * whether that rollback is expected to start the kept image instead of
     * building. Lists eager-load the project with its active deployment's
     * node and its previous active deployment.
     *
     * @return array{image_expected: bool}|null
     */
    private function rollbackTarget(): ?array
    {
        $project = $this->resource->project;

        if ($project === null || ! DeploymentRollbackEligibility::isTarget($this->resource, $project)) {
            return null;
        }

        return ['image_expected' => DeploymentRollbackEligibility::imageExpected($this->resource, $project)];
    }

    /**
     * Why a queued deployment has not started. Waiting for the project's
     * inspection comes first: the command is held whatever the node. Then only
     * a node that can take workload right now counts: a pinned node that went
     * offline or is draining leaves the deployment waiting all the same.
     */
    private function queueState(): ?DeploymentQueueState
    {
        if ($this->resource->status !== DeploymentStatus::Queued) {
            return null;
        }

        if ($this->resource->awaiting_inspection_until !== null) {
            return DeploymentQueueState::WaitingForInspection;
        }

        $node = $this->resource->agentNode;

        return $node !== null && app(AgentCommandEligibilityService::class)->nodeAcceptsWorkload($node)
            ? DeploymentQueueState::Assigned
            : DeploymentQueueState::WaitingForNode;
    }
}
