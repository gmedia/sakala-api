<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Models\Deployment;
use App\Models\Project;
use App\Support\Project\DeploymentConfigFingerprint;
use App\Support\Project\ProjectPreviewPresenter;

/**
 * Gives a deployment created before its stack was known the build fingerprint
 * it would have had with the inspection, once that inspection lands.
 *
 * A deployment created while the project's inspection is pending, or still
 * describes another root directory (a root change followed at once by a
 * redeploy), stores DeploymentConfigFingerprint::forBuildWithoutInspection():
 * every variable counts (VariableBuildPolicy rule 5). Building it is right,
 * but keeping that fingerprint is not: when the inspection then finds a plain
 * server, every later deployment stores the plain-server fingerprint, which
 * never equals it, so the first runtime-only variable change after it (and
 * after a rollback to it, which inherits it) builds once for nothing.
 *
 * Only a deployment whose inputs are exactly the inspected ones is corrected:
 * the inspected commit, and a build fingerprint equal to the fallback over the
 * project's current root directory, build and start commands, and variables
 * with their values. A deployment built from anything else (another commit, a
 * variable changed since) keeps its fingerprint and builds next time, as
 * before. A static site, a prerendering framework, or a stack still unknown
 * gets the fallback value from forBuild() as well, so nothing changes for
 * them and a variable change keeps building them.
 *
 * Called under the project row lock, which CreateDeploymentAction takes before
 * it computes a fingerprint, so every deployment created before the inspection
 * was written has committed and is seen here, and every later one already
 * classifies with it.
 */
final class ClassifyFallbackBuildFingerprintsAction
{
    /** @return int How many deployments got the classified fingerprint. */
    public function handle(Project $lockedProject, string $inspectedCommitSha): int
    {
        if (ProjectPreviewPresenter::usableInspection($lockedProject) === null) {
            return 0;
        }

        // Loaded once, so both fingerprints see the same values.
        $variables = $lockedProject->environmentVariables()->get();
        $fallback = DeploymentConfigFingerprint::forBuildWithoutInspection($lockedProject, $variables);
        $classified = DeploymentConfigFingerprint::forBuild($lockedProject, $variables);

        if (hash_equals($fallback, $classified)) {
            return 0;
        }

        return Deployment::query()
            ->where('project_id', $lockedProject->id)
            ->where('commit_sha', $inspectedCommitSha)
            ->where('build_fingerprint', $fallback)
            ->update(['build_fingerprint' => $classified]);
    }
}
