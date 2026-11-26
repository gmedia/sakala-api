<?php

declare(strict_types=1);

namespace App\Services\CustomDomain\Dns;

use App\Data\CustomDomain\CaaRecordData;

/**
 * The DNS questions a custom domain check asks (ADR-024). Each method
 * answers for records exactly at `$name`, without following a CNAME, so the
 * checker decides how a chain is followed. Names are lowercase ASCII
 * without a trailing dot, and so are the names returned.
 *
 * NetDnsResolver asks the zone's authoritative name servers; tests bind a
 * fake and never reach real DNS.
 */
interface DnsResolver
{
    /**
     * TXT values at `$name`, the character strings of each record joined.
     *
     * @return list<string>
     *
     * @throws DnsLookupException
     */
    public function txt(string $name): array;

    /**
     * The CNAME target at `$name`, or null when `$name` is not an alias.
     *
     * @throws DnsLookupException
     */
    public function cname(string $name): ?string;

    /**
     * A and AAAA addresses at `$name`, sorted.
     *
     * @return list<string>
     *
     * @throws DnsLookupException
     */
    public function addresses(string $name): array;

    /**
     * CAA records at `$name`.
     *
     * @return list<CaaRecordData>
     *
     * @throws DnsLookupException
     */
    public function caa(string $name): array;

    /**
     * A and AAAA addresses `$name` resolves to through a recursive resolver,
     * CNAMEs followed: the addresses of the target.
     *
     * @return list<string>
     *
     * @throws DnsLookupException
     */
    public function resolveAddresses(string $name): array;
}
