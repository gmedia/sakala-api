<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Data\CustomDomain\CaaRecordData;
use App\Services\CustomDomain\Dns\DnsLookupException;
use App\Services\CustomDomain\Dns\DnsResolver;

/**
 * DNS for tests: answers from what the test published, never from the
 * network. A name listed in `$failing` throws like a timeout for every
 * question about it.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    public array $txt = [];

    /** @var array<string, string> */
    public array $cnames = [];

    /** @var array<string, list<string>> */
    public array $addresses = [];

    /** @var array<string, list<CaaRecordData>> */
    public array $caa = [];

    /** @var list<string> */
    public array $failing = [];

    /** @var list<string> Questions asked, as "TYPE name", in order. */
    public array $asked = [];

    public function txt(string $name): array
    {
        $this->ask('TXT', $name);

        return $this->txt[$name] ?? [];
    }

    public function cname(string $name): ?string
    {
        $this->ask('CNAME', $name);

        return $this->cnames[$name] ?? null;
    }

    public function addresses(string $name): array
    {
        $this->ask('A', $name);

        return $this->addresses[$name] ?? [];
    }

    public function caa(string $name): array
    {
        $this->ask('CAA', $name);

        return $this->caa[$name] ?? [];
    }

    public function resolveAddresses(string $name): array
    {
        $this->ask('RESOLVE', $name);
        $seen = 0;

        while (isset($this->cnames[$name]) && $seen++ < 10) {
            $name = $this->cnames[$name];
        }

        return $this->addresses[$name] ?? [];
    }

    private function ask(string $type, string $name): void
    {
        $this->asked[] = "{$type} {$name}";

        if (in_array($name, $this->failing, true)) {
            throw new DnsLookupException("timeout asking {$type} {$name}");
        }
    }
}
