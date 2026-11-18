<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an Edge instance counts as unhealthy (`edge:check-health`, the admin
 * Edge list). Each condition must have held, continuously, for longer than
 * `sakala.edge.stale_after_seconds`. See docs/EDGE_API.md#pemantauan.
 */
enum EdgeHealthReason: string
{
    /** The Edge has not fetched the route table (`last_seen_at` is older than the threshold). */
    case Stale = 'stale';

    /** The Edge reports a status other than `ok`, the code sakala-edge failed to apply the table with. */
    case Status = 'status';

    /** The revision the Edge serves, or `none`, differs from the current route table revision. */
    case RevisionBehind = 'revision_behind';
}
