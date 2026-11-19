<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a change to a project variable makes the next deployment build instead
 * of reusing the live image (VariableBuildPolicy). The first three are
 * project-level: every variable counts. `BuildTimePrefix` is never a
 * project-level reason; it names the per-variable reason of a plain server
 * (BuildTimeVariable), so this enum lists every reason the Console can show.
 */
enum VariableBuildReason: string
{
    /** Served as files, without a server process: every variable is read at build. */
    case StaticSite = 'static_site';

    /** A framework that can render pages at build. */
    case PrerenderFramework = 'prerender_framework';

    /** No usable inspection, or a stack Sakala cannot classify: every variable counts, to be safe. */
    case UnknownStack = 'unknown_stack';

    /** The key is a build-time prefix or key of BuildTimeVariable. Per variable only. */
    case BuildTimePrefix = 'build_time_prefix';
}
