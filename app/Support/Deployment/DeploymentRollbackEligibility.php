<?php

declare(strict_types=1);

namespace App\Support\Deployment;

use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RollbackIneligibleReason;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;

/**
 * Whether a deployment can be rolled back to, and whether that rollback is
 * expected to start its kept image instead of building. Shared by
 * CreateDeploymentAction (under the project lock) and DeploymentResource
 * (`rollback_target`), so both answer the same. Pure apart from the project's
 * `activeDeployment.agentNode` and `previousActiveDeployment` relations, which
 * lists eager-load.
 */
final class DeploymentRollbackEligibility
{
    /**
     * Null when `$target` can be gone back to: it succeeded, does not serve
     * the project now, ran a known commit, and ran the project's current
     * branch. Suspension is the caller's (PROJECT_SUSPENDED).
     */
    public static function reason(Deployment $target, Project $project): ?RollbackIneligibleReason
    {
        return match (true) {
            $target->status !== DeploymentStatus::Succeeded => RollbackIneligibleReason::NotSucceeded,
            $project->active_deployment_id === $target->id => RollbackIneligibleReason::AlreadyActive,
            $target->commit_sha === null => RollbackIneligibleReason::NoCommit,
            $target->branch !== $project->branch => RollbackIneligibleReason::BranchChanged,
            default => null,
        };
    }

    /** What `rollback_target` is non-null for: eligible, on a project that is not suspended. */
    public static function isTarget(Deployment $target, Project $project): bool
    {
        return $project->status !== ProjectStatus::Suspended && self::reason($target, $project) === null;
    }

    /**
     * Whether the node is expected to still hold `$target`'s image, so a
     * rollback reuses it and builds nothing: the image is the live one or the
     * one kept as `previous` (its builder is the builder of the active or the
     * previous active deployment), the target ran on the active deployment's
     * node, and that node advertises `image-rollback` (which keeps that image)
     * and `image-reuse` (which starts it). A target without a build
     * fingerprint (from before image reuse) always builds. A wrong `true`
     * costs only time: the agent builds when the image is gone.
     */
    public static function imageExpected(Deployment $target, Project $project): bool
    {
        $builder = $target->imageBuilderId();
        $active = $project->active_deployment_id === null ? null : $project->activeDeployment;

        if ($builder === null
            || $target->build_fingerprint === null
            || $active === null
            || $active->agent_node_id === null
            || $target->agent_node_id !== $active->agent_node_id) {
            return false;
        }

        $node = $active->agentNode;

        if ($node === null
            || ! $node->hasCapability(AgentNode::IMAGE_ROLLBACK_CAPABILITY)
            || ! $node->hasCapability(AgentNode::IMAGE_REUSE_CAPABILITY)) {
            return false;
        }

        if ($builder === $active->imageBuilderId()) {
            return true;
        }

        $previous = $project->previous_active_deployment_id === null ? null : $project->previousActiveDeployment;

        return $previous !== null && $builder === $previous->imageBuilderId();
    }
}
