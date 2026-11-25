<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a custom domain claim stands (ADR-024). Only `verified` routes:
 * the Edge serves it and the project's commands carry it. A claim is never
 * deleted automatically; deleting is the user's (a soft delete).
 */
enum CustomDomainStatus: string
{
    /** Added; waiting until TXT, pointing, and CAA pass together. Checked every minute, then every 15 minutes. */
    case Pending = 'pending';

    /** TXT, pointing, and CAA passed; routed and re-checked every 6 hours. */
    case Verified = 'verified';

    /** A pending claim that did not pass within 72 hours. Checked again only on "check now". */
    case Failed = 'failed';

    /** A verified domain that kept failing for 72 hours, or that an admin disabled. Not routed; checked daily for 30 days. */
    case Disabled = 'disabled';

    /** A disabled claim another project verified the name for. It can never route again. */
    case Released = 'released';

    /**
     * Statuses that count toward `max_custom_domains`: every claim the
     * workspace still holds. A released claim cannot come back and is never
     * checked, so it does not count.
     *
     * @return list<self>
     */
    public static function countedCases(): array
    {
        return [self::Pending, self::Verified, self::Failed, self::Disabled];
    }

    /** Whether "check now" may ask DNS about the claim. */
    public function isCheckable(): bool
    {
        return $this !== self::Released;
    }
}
