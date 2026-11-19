<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Data\Deployment\DeploymentBuildPlanData;
use App\Models\AgentNode;
use App\Models\Project;

/**
 * The one place that decides whether a new deployment builds an image or
 * reuses the live one. It reuses only when all of these hold:
 *
 * 1. nobody asked for a fresh build (`force_build`);
 * 2. the project has an active deployment, whatever its runtime status
 *    (serving, sleeping, or crashed, its image is still on the node);
 * 3. that deployment runs the same commit;
 * 4. it has a build fingerprint (deployments from before image reuse do not)
 *    equal to the new one (DeploymentConfigFingerprint::forBuild());
 * 5. the new deployment is pinned to that deployment's node, and the node
 *    advertises AgentNode::IMAGE_REUSE_CAPABILITY;
 * 6. the deployment that built the image is known: the active one, or its
 *    source when it reused itself, so chains point at the builder.
 *
 * Anything else builds, as every deployment did before. A wrong "reuse" costs
 * no correctness: the agent checks the image's labels and builds when it does
 * not match (`deployment.image.reuse_skipped`).
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
        string $buildFingerprint,
        bool $forceBuild,
    ): DeploymentBuildPlanData {
        if ($forceBuild || $lockedProject->active_deployment_id === null) {
            return DeploymentBuildPlanData::build();
        }

        $active = $lockedProject->activeDeployment;

        if ($active === null
            || $active->commit_sha !== $commitSha
            || $active->build_fingerprint === null
            || ! hash_equals($active->build_fingerprint, $buildFingerprint)
            || $node === null
            || $active->agent_node_id !== $node->id
            || ! $node->hasCapability(AgentNode::IMAGE_REUSE_CAPABILITY)) {
            return DeploymentBuildPlanData::build();
        }

        $source = $active->imageBuilderId();

        return $source === null
            ? DeploymentBuildPlanData::build()
            : DeploymentBuildPlanData::reuse($source);
    }
}
