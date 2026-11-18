<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a project that holds a workload on a node has no route in the Edge
 * route table (`skipped_routes[].reason`). Every skip is listed, so the Edge
 * can alert instead of silently dropping a project.
 */
enum EdgeRouteSkipReason: string
{
    /** The node holding the workload has no edge address: neither an admin override nor one reported in its heartbeat. */
    case NodeWithoutEdgeAddress = 'node_without_edge_address';

    /** The project's domain is not one label under the configured project domain (`SAKALA_PROJECT_DEFAULT_DOMAIN`). */
    case InvalidHost = 'invalid_host';
}
