<?php

declare(strict_types=1);

namespace App\Services\CustomDomain;

use App\Data\CustomDomain\CaaRecordData;
use App\Data\CustomDomain\CustomDomainCheckResultData;
use App\Enums\CustomDomainCheckReason;
use App\Enums\CustomDomainKind;
use App\Enums\CustomDomainRoutingMatch;
use App\Models\ProjectDomain;
use App\Services\CustomDomain\Dns\DnsLookupException;
use App\Services\CustomDomain\Dns\DnsResolver;

/**
 * The DNS check of one claim (ADR-024): three parts, all of which must pass
 * at the same time.
 *
 * - Ownership: one TXT value at `_sakala.<domain>` is exactly
 *   `sakala-verify=<this claim's token>`. Another claim's token does not count.
 * - Pointing: a subdomain's CNAME chain reaches the target; its own A or
 *   AAAA records are refused (`cname_required`), because they break when the
 *   Edge moves. An apex passes with a CNAME chain to the target or with
 *   addresses that are all the target's, at least one IPv4 (CNAME
 *   flattening, ALIAS, ANAME; a literal A record looks the same).
 * - CAA: the closest CAA record set, from the domain up to its registrable
 *   domain, allows Let's Encrypt, or there is none.
 *
 * Any DNS error fails the part it hit with `dns_error`. Nothing here writes;
 * CheckCustomDomainAction applies the result. The check asks DNS outside any
 * transaction, so no row lock waits on a name server.
 */
final class CustomDomainChecker
{
    /** CAA tag that names the CAs allowed to issue ordinary certificates. */
    private const CAA_ISSUE_TAG = 'issue';

    /** CAA tags a CA understands; a critical record with another tag forbids issuance. */
    private const CAA_KNOWN_TAGS = ['issue', 'issuewild', 'iodef'];

    public function __construct(
        private readonly CustomDomainPolicy $policy,
        private readonly CustomDomainNameParser $names,
    ) {}

    public function check(ProjectDomain $domain): CustomDomainCheckResultData
    {
        // A fresh resolver per check: it caches zone lookups for this check only.
        $dns = app(DnsResolver::class);
        $reasons = [];

        $ownershipReason = $this->ownership($dns, $domain);
        [$routingReason, $match] = $this->routing($dns, $domain);
        $caaReason = $this->caa($dns, $domain->hostname);

        foreach ([$ownershipReason, $routingReason, $caaReason] as $reason) {
            if ($reason !== null && ! in_array($reason, $reasons, true)) {
                $reasons[] = $reason;
            }
        }

        return new CustomDomainCheckResultData(
            ownership: $ownershipReason === null,
            routing: $routingReason === null,
            caa: $caaReason === null,
            reasons: $reasons,
            routingMatch: $routingReason === null ? $match : null,
        );
    }

    private function ownership(DnsResolver $dns, ProjectDomain $domain): ?CustomDomainCheckReason
    {
        try {
            $values = array_map('trim', $dns->txt($domain->txtName()));
        } catch (DnsLookupException) {
            return CustomDomainCheckReason::DnsError;
        }

        if (in_array($domain->txtValue(), $values, true)) {
            return null;
        }

        foreach ($values as $value) {
            if (str_starts_with($value, CustomDomainPolicy::TXT_VALUE_PREFIX)) {
                return CustomDomainCheckReason::TxtMismatch;
            }
        }

        return CustomDomainCheckReason::TxtMissing;
    }

    /**
     * @return array{0: CustomDomainCheckReason|null, 1: CustomDomainRoutingMatch|null}
     */
    private function routing(DnsResolver $dns, ProjectDomain $domain): array
    {
        $target = $this->policy->target();

        try {
            $name = $domain->hostname;

            for ($hop = 0; $hop < CustomDomainPolicy::MAX_CNAME_HOPS; $hop++) {
                $alias = $dns->cname($name);

                if ($alias === null) {
                    break;
                }

                if ($alias === $target) {
                    return [null, CustomDomainRoutingMatch::Cname];
                }

                $name = $alias;
            }

            if ($dns->cname($name) !== null) {
                // Still an alias after MAX_CNAME_HOPS: a loop or a chain too long to follow.
                return [CustomDomainCheckReason::PointsElsewhere, null];
            }

            $addresses = $dns->addresses($name);

            if ($addresses === []) {
                return [CustomDomainCheckReason::NoAddress, null];
            }

            $targetAddresses = $dns->resolveAddresses($target);
        } catch (DnsLookupException) {
            return [CustomDomainCheckReason::DnsError, null];
        }

        if ($targetAddresses === []) {
            // The target itself does not resolve: Sakala's problem, reported as unknown.
            return [CustomDomainCheckReason::DnsError, null];
        }

        $ours = array_intersect($addresses, $targetAddresses);
        $others = array_diff($addresses, $targetAddresses);

        if ($domain->kind === CustomDomainKind::Subdomain) {
            return [$others === [] ? CustomDomainCheckReason::CnameRequired : CustomDomainCheckReason::PointsElsewhere, null];
        }

        if ($ours === [] || ! $this->hasIpv4($ours)) {
            return [CustomDomainCheckReason::PointsElsewhere, null];
        }

        if ($others !== []) {
            return [CustomDomainCheckReason::ExtraAddresses, null];
        }

        return [null, CustomDomainRoutingMatch::Addresses];
    }

    /**
     * RFC 8659 tree climbing, bounded by the registrable domain: the first
     * name with CAA records decides. A CAA lookup on an alias finds no
     * record of its own here and moves on to the parent.
     */
    private function caa(DnsResolver $dns, string $hostname): ?CustomDomainCheckReason
    {
        $registrable = $this->names->registrableDomain($hostname) ?? $hostname;
        $labels = explode('.', $hostname);

        try {
            while ($labels !== []) {
                $name = implode('.', $labels);
                $records = $dns->caa($name);

                if ($records !== []) {
                    return $this->caaAllows($records) ? null : CustomDomainCheckReason::CaaForbidsIssuer;
                }

                if ($name === $registrable) {
                    return null;
                }

                array_shift($labels);
            }
        } catch (DnsLookupException) {
            return CustomDomainCheckReason::DnsError;
        }

        return null;
    }

    /** @param  list<CaaRecordData>  $records */
    private function caaAllows(array $records): bool
    {
        $issuers = [];

        foreach ($records as $record) {
            if ($record->isCritical() && ! in_array($record->tag, self::CAA_KNOWN_TAGS, true)) {
                return false;
            }

            if ($record->tag === self::CAA_ISSUE_TAG) {
                // `letsencrypt.org; accounturi=...`: the domain is before the first `;`.
                $issuers[] = strtolower(trim(explode(';', $record->value, 2)[0]));
            }
        }

        // No `issue` record: any CA may issue an ordinary certificate.
        return $issuers === [] || in_array(CustomDomainPolicy::CERTIFICATE_AUTHORITY, $issuers, true);
    }

    /** @param  array<int, string>  $addresses */
    private function hasIpv4(array $addresses): bool
    {
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return true;
            }
        }

        return false;
    }
}
