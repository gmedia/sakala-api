<?php

declare(strict_types=1);

namespace App\Enums;

/** Package managers whose workspaces the agent builds from the workspace root. */
enum JavaScriptPackageManager: string
{
    case Npm = 'npm';
    case Pnpm = 'pnpm';
    case Yarn = 'yarn';
    case Bun = 'bun';
}
