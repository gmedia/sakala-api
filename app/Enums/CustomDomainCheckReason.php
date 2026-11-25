<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a DNS check of a custom domain did not pass (ADR-024). A check reports
 * every reason it found, ownership first, then pointing, then CAA.
 */
enum CustomDomainCheckReason: string
{
    /** `_sakala.<domain>` has no `sakala-verify=` TXT value. */
    case TxtMissing = 'txt_missing';

    /** `_sakala.<domain>` has `sakala-verify=` values, but none with this claim's token. */
    case TxtMismatch = 'txt_mismatch';

    /** A subdomain answers with addresses of its own (an A or AAAA record), not a CNAME to the target. */
    case CnameRequired = 'cname_required';

    /** The domain's CNAME chain or addresses lead somewhere other than the target. */
    case PointsElsewhere = 'points_elsewhere';

    /** An apex domain has the target's addresses plus others (an old AAAA, for example). */
    case ExtraAddresses = 'extra_addresses';

    /** The domain has no CNAME and no address at all. */
    case NoAddress = 'no_address';

    /** A CAA record of the domain or a parent allows certificate authorities other than Let's Encrypt only. */
    case CaaForbidsIssuer = 'caa_forbids_issuer';

    /** A name server timed out, refused, or failed (SERVFAIL); the result is unknown and counts as failing. */
    case DnsError = 'dns_error';

    /** Every record passed, but another project verified the same name first. */
    case DomainTaken = 'domain_taken';
}
