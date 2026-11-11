<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Enums\DeploymentFailureCategory;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentFailureClassifier;
use App\Support\Admin\DeploymentDuration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the cross-project failure table. `failure` matches the field of
 * the same name on the deployment resource, except that a failure without a
 * code reads as category `unknown` with `code: null` instead of null.
 *
 * @mixin Deployment
 *
 * @property Deployment $resource
 */
final class FailedDeploymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $deployment = $this->resource;
        $project = $deployment->project;
        $requester = $deployment->requested_by === null ? null : $deployment->requester;
        $classified = $deployment->failure_code === null
            ? null
            : app(DeploymentFailureClassifier::class)->classify($deployment->failure_code);

        return [
            'id' => $deployment->id,
            /**
             * The project, also when it has been deleted and waits for purge.
             */
            'project' => $project === null ? null : [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'workspace_id' => $project->workspace_id,
                'deleted' => $project->trashed(),
            ],
            'sequence' => $deployment->sequence,
            'branch' => $deployment->branch,
            'commit_sha' => $deployment->commit_sha,
            /** `manual`, `webhook`, or another `DeploymentTrigger`. */
            'trigger' => $deployment->trigger->value,
            'agent_node_id' => $deployment->agent_node_id,
            /**
             * Who asked for the deployment; null for an account deleted since.
             */
            'requested_by' => $requester === null ? null : ['id' => $requester->id, 'name' => $requester->name, 'username' => $requester->username],
            /** @var array{code: string|null, category: string, summary: string|null, user_fixable: bool|null, detail: string|null, stage: string|null} */
            'failure' => [
                'code' => $deployment->failure_code,
                'category' => $classified?->category->value ?? DeploymentFailureCategory::Unknown->value,
                /** Indonesian summary from the classifier; null without a code. */
                'summary' => $classified !== null ? $classified->summary : $deployment->failure_summary,
                'user_fixable' => $classified?->userFixable,
                /** The redacted agent message, at most 500 characters. */
                'detail' => $deployment->failure_detail,
                'stage' => $deployment->failure_stage?->value,
            ],
            'created_at' => $deployment->created_at?->toAtomString(),
            /** @var string|null */
            'started_at' => $deployment->started_at?->toAtomString(),
            /** @var string|null */
            'finished_at' => $deployment->finished_at?->toAtomString(),
            /**
             * From `started_at` (or `created_at`) to `finished_at`, in seconds.
             */
            'duration_seconds' => DeploymentDuration::seconds($deployment),
        ];
    }
}
