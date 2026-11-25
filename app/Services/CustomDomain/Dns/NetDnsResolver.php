<?php

declare(strict_types=1);

namespace App\Services\CustomDomain\Dns;

use App\Data\CustomDomain\CaaRecordData;
use App\Services\CustomDomain\CustomDomainPolicy;
use NetDNS2\Data\Text;
use NetDNS2\ENUM\RR\Code;
use NetDNS2\Exception as NetDnsException;
use NetDNS2\Resolver;
use NetDNS2\RR;
use NetDNS2\RR\A;
use NetDNS2\RR\AAAA;
use NetDNS2\RR\CAA;
use NetDNS2\RR\CNAME;
use NetDNS2\RR\NS;
use NetDNS2\RR\TXT;

/**
 * DnsResolver over mikepultz/netdns2. Records of a name are asked from the
 * authoritative name servers of its zone, as a certificate authority does,
 * so a negative answer cached by a recursive resolver does not keep a user
 * who checked too early waiting for its TTL. The zone is found by asking a
 * recursive resolver for NS records from the name upward; when no zone is
 * found that way, the recursive resolver answers instead.
 *
 * One instance serves one check: zone lookups are cached on the instance,
 * never across checks, so a moved zone is seen at the next check.
 */
final class NetDnsResolver implements DnsResolver
{
    /** Name servers of a zone asked at most; one answering is enough. */
    private const MAX_NAME_SERVERS = 4;

    /** @var array<string, Resolver|null> Authoritative resolver by queried name; null when the recursive one answers. */
    private array $authoritative = [];

    private ?Resolver $recursive = null;

    public function __construct(
        private readonly CustomDomainPolicy $policy,
    ) {}

    public function txt(string $name): array
    {
        $values = [];

        foreach ($this->records($name, 'TXT') as $record) {
            if ($record instanceof TXT) {
                $values[] = implode('', array_map(static fn (Text $text): string => $text->value(), $record->text));
            }
        }

        return $values;
    }

    public function cname(string $name): ?string
    {
        foreach ($this->records($name, 'CNAME') as $record) {
            if ($record instanceof CNAME) {
                return self::normalize($record->cname->value());
            }
        }

        return null;
    }

    public function addresses(string $name): array
    {
        return self::addressesOf([...$this->records($name, 'A'), ...$this->records($name, 'AAAA')]);
    }

    public function caa(string $name): array
    {
        $records = [];

        foreach ($this->records($name, 'CAA') as $record) {
            if ($record instanceof CAA) {
                $records[] = new CaaRecordData($record->flags, strtolower($record->tag->value()), $record->value->value());
            }
        }

        return $records;
    }

    public function resolveAddresses(string $name): array
    {
        return self::addressesOf([
            ...$this->ask($this->recursive(), $name, 'A'),
            ...$this->ask($this->recursive(), $name, 'AAAA'),
        ]);
    }

    /**
     * Addresses of the A and AAAA records among `$records`, sorted, without duplicates.
     *
     * @param  list<RR>  $records
     * @return list<string>
     */
    private static function addressesOf(array $records): array
    {
        $addresses = [];

        foreach ($records as $record) {
            if ($record instanceof A || $record instanceof AAAA) {
                $addresses[] = $record->address->value();
            }
        }

        sort($addresses);

        return array_values(array_unique($addresses));
    }

    /**
     * Records of `$type` owned by `$name` itself; a CNAME answer for another
     * type is left out.
     *
     * @return list<RR>
     */
    private function records(string $name, string $type): array
    {
        $resolver = $this->authoritativeFor($name) ?? $this->recursive();

        return array_values(array_filter(
            $this->ask($resolver, $name, $type),
            static fn (RR $record): bool => $record->type->label() === $type && self::normalize((string) $record->name) === $name,
        ));
    }

    /**
     * @return list<RR>
     *
     * @throws DnsLookupException
     */
    private function ask(Resolver $resolver, string $name, string $type): array
    {
        try {
            return array_values($resolver->query($name, $type)->answer);
        } catch (NetDnsException $exception) {
            if ($exception->getCode() === Code::NXDOMAIN->value) {
                return [];
            }

            throw new DnsLookupException("DNS {$type} {$name}: {$exception->getMessage()}", previous: $exception);
        }
    }

    /**
     * A resolver that asks the authoritative name servers of the zone
     * `$name` is in, without recursion; null when no zone was found.
     */
    private function authoritativeFor(string $name): ?Resolver
    {
        if (array_key_exists($name, $this->authoritative)) {
            return $this->authoritative[$name];
        }

        $labels = explode('.', $name);

        // The zone cut is the closest name with NS records; a TLD is never asked.
        while (count($labels) >= 2) {
            $candidate = implode('.', $labels);
            $servers = $this->nameServerAddresses($candidate);

            if ($servers !== []) {
                return $this->authoritative[$name] = new Resolver([
                    'nameservers' => $servers,
                    'recurse' => false,
                    'timeout' => $this->policy->dnsTimeoutSeconds(),
                ]);
            }

            array_shift($labels);
        }

        return $this->authoritative[$name] = null;
    }

    /** @return list<string> IPv4 addresses of the zone's name servers; empty when `$zone` is not a zone. */
    private function nameServerAddresses(string $zone): array
    {
        try {
            $records = array_filter(
                $this->ask($this->recursive(), $zone, 'NS'),
                static fn (RR $record): bool => $record->type->label() === 'NS' && self::normalize((string) $record->name) === $zone,
            );
        } catch (DnsLookupException) {
            return [];
        }

        $addresses = [];

        foreach (array_slice(array_values($records), 0, self::MAX_NAME_SERVERS) as $record) {
            if (! $record instanceof NS) {
                continue;
            }

            try {
                foreach ($this->ask($this->recursive(), self::normalize($record->nsdname->value()), 'A') as $address) {
                    if ($address instanceof A) {
                        $addresses[] = $address->address->value();
                    }
                }
            } catch (DnsLookupException) {
                continue;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function recursive(): Resolver
    {
        if ($this->recursive !== null) {
            return $this->recursive;
        }

        $options = ['timeout' => $this->policy->dnsTimeoutSeconds()];
        $resolvers = $this->policy->resolvers();

        if ($resolvers !== []) {
            $options['nameservers'] = $resolvers;
        }

        return $this->recursive = new Resolver($options);
    }

    private static function normalize(string $name): string
    {
        return rtrim(strtolower($name), '.');
    }
}
