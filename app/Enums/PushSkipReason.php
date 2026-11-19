<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a push to a project's branch did not deploy that project although
 * auto-deploy is on, and nothing went wrong. Pushes skipped because of a limit
 * or another problem are `project.auto_deploy_skipped` audit events instead,
 * and notify people; these do not.
 */
enum PushSkipReason: string
{
    /**
     * Every changed path lies in the root directory of another project of the
     * same workspace, repository, and branch: a monorepo push for another app.
     */
    case NoChangesInRootDirectory = 'no_changes_in_root_directory';
}
