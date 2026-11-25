<?php

declare(strict_types=1);

namespace App\Enums;

/** How the last passing check found the domain pointed at Sakala (ADR-024). */
enum CustomDomainRoutingMatch: string
{
    /** A CNAME chain reaches the target. */
    case Cname = 'cname';

    /**
     * An apex domain's addresses are all the target's: CNAME flattening,
     * ALIAS, or ANAME, or a literal A record that cannot be told apart and
     * breaks when the target's addresses change.
     */
    case Addresses = 'addresses';
}
