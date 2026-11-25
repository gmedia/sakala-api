<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a custom domain is its registrable domain (`contoh.com`,
 * `kampus.ac.id`) or a name below one (`www.contoh.com`), by the Public
 * Suffix List. The pointing rule differs (ADR-024).
 */
enum CustomDomainKind: string
{
    /** Pointed with CNAME flattening, ALIAS, or ANAME: its addresses must be the target's. */
    case Apex = 'apex';

    /** Pointed with a CNAME chain that reaches the target. */
    case Subdomain = 'subdomain';
}
