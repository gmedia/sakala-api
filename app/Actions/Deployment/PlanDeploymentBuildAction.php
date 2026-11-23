<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Data\Deployment\DeploymentBuildPlanData;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;

/**
 * The one place that decides whether a new deployment builds an image or
 * reuses one a node already has. The candidate is the project's active
 * deployment, or for a rollback the deployment it goes back to. It reuses
 * only when all of these hold:
 *
 * 1. nobody asked for a fresh build (`force_build`);
 * 2. there is a candidate: the project has an active deployment, whatever its
 *    runtime status (serving, sleeping, or crashed, its image is still on the
 *    node), or the rollback names its target;
 * 3. the candidate runs the same commit;
 * 4. it has a build fingerprint (deployments from before image reuse do not)
 *    equal to the new one (DeploymentConfigFingerprint::forBuild(), or the
 *    recorded one of the deployment whose settings the new one runs with);
 * 5. the new deployment is pinned to the candidate's node, and the node
 *    advertises AgentNode::IMAGE_REUSE_CAPABILITY;
 * 6. the deployment that built the image is known: the candidate, or its
 *    source when it reused itself, so chains point at the builder.
 *
 * Anything else builds, as every deployment did before. A wrong "reuse" costs
 * no correctness: the agent checks the image's labels and builds when it does
 * not match (`deployment.image.reuse_skipped`), which is also how a rollback
 * to an image the node pruned ends up building that commit.
 *
 * The caller holds the project row lock, under which `active_deployment_id`
 * is written, so the active deployment cannot change while this decides.
 */
final class PlanDeploymentBuildAction
{
    public function handle(
        Project $lockedProject,
        ?AgentNode $node,
        string $commitSha,
        ?string $buildFingerprint,
        bool $forceBuild,
        ?Deployment $rollbackTarget = null,
    ): DeploymentBuildPlanData {
        if ($forceBuild || $buildFingerprint === null) {
            return DeploymentBuildPlanData::build();
        }

        $candidate = $rollbackTarget
            ?? ($lockedProject->active_deployment_id === null ? null : $lockedProject->activeDeployment);

        if ($candidate === null
            || $candidate->commit_sha !== $commitSha
            || $candidate->build_fingerprint === null
            || ! hash_equals($candidate->build_fingerprint, $buildFingerprint)
            || $node === null
            || $candidate->agent_node_id !== $node->id
            || ! $node->hasCapability(AgentNode::IMAGE_REUSE_CAPABILITY)) {
            return DeploymentBuildPlanData::build();
        }

        $source = $candidate->imageBuilderId();

        return $source === null
            ? DeploymentBuildPlanData::build()
            : DeploymentBuildPlanData::reuse($source);
    }
}
