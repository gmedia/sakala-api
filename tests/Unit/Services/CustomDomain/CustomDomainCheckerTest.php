<?php

declare(strict_types=1);

use App\Data\CustomDomain\CaaRecordData;
use App\Data\CustomDomain\CustomDomainCheckResultData;
use App\Enums\CustomDomainCheckReason as Reason;
use App\Enums\CustomDomainKind;
use App\Enums\CustomDomainRoutingMatch;
use App\Models\ProjectDomain;
use App\Services\CustomDomain\CustomDomainChecker;
use App\Services\CustomDomain\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fakes\FakeDnsResolver;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableCustomDomains();
    $this->dns = fakeDns();
});

function claim(string $hostname, CustomDomainKind $kind = CustomDomainKind::Subdomain): ProjectDomain
{
    return ProjectDomain::factory()->make(['hostname' => $hostname, 'kind' => $kind, 'verification_token' => 'token-'.md5($hostname)]);
}

function checkClaim(ProjectDomain $domain): CustomDomainCheckResultData
{
    return app(CustomDomainChecker::class)->check($domain);
}

test('a subdomain with its TXT and a CNAME to the target passes', function (): void {
    $domain = claim('www.contoh.com');
    publishDnsFor($this->dns, $domain);

    $result = checkClaim($domain);

    expect($result->passed())->toBeTrue()
        ->and($result->routingMatch)->toBe(CustomDomainRoutingMatch::Cname)
        ->and($result->reasons)->toBe([]);
});

test('a CNAME chain through the user\'s own names reaches the target', function (): void {
    $domain = claim('www.contoh.com');
    $this->dns->txt[$domain->txtName()] = [$domain->txtValue()];
    $this->dns->cnames['www.contoh.com'] = 'situs.contoh.net';
    $this->dns->cnames['situs.contoh.net'] = CUSTOM_DOMAIN_TARGET;

    expect(checkClaim($domain)->passed())->toBeTrue();
});

test('ownership needs this claim\'s token, not any sakala-verify value', function (array $values, Reason $reason): void {
    $domain = claim('www.contoh.com');
    publishDnsFor($this->dns, $domain);
    $this->dns->txt[$domain->txtName()] = $values;

    $result = checkClaim($domain);

    expect($result->passed())->toBeFalse()
        ->and($result->ownership)->toBeFalse()
        ->and($result->routing)->toBeTrue()
        ->and($result->reasons)->toBe([$reason]);
})->with([
    'no TXT' => [[], Reason::TxtMissing],
    'unrelated TXT' => [['v=spf1 -all'], Reason::TxtMissing],
    'another claim\'s token' => [['sakala-verify=someone-else'], Reason::TxtMismatch],
]);

test('the token is found among several TXT values', function (): void {
    $domain = claim('www.contoh.com');
    publishDnsFor($this->dns, $domain);
    $this->dns->txt[$domain->txtName()] = ['sakala-verify=other', " {$domain->txtValue()} "];

    expect(checkClaim($domain)->passed())->toBeTrue();
});

test('a subdomain with a plain A record to the Edge address is refused: CNAME only', function (): void {
    $domain = claim('www.contoh.com');
    $this->dns->txt[$domain->txtName()] = [$domain->txtValue()];
    $this->dns->addresses['www.contoh.com'] = [CUSTOM_DOMAIN_EDGE_IPV4];

    expect(checkClaim($domain)->reasons)->toBe([Reason::CnameRequired]);
});

test('a subdomain pointed somewhere else is refused', function (array $cnames, array $addresses, Reason $reason): void {
    $domain = claim('www.contoh.com');
    $this->dns->txt[$domain->txtName()] = [$domain->txtValue()];
    $this->dns->cnames = $cnames;
    $this->dns->addresses = [CUSTOM_DOMAIN_TARGET => [CUSTOM_DOMAIN_EDGE_IPV4], ...$addresses];

    expect(checkClaim($domain)->reasons)->toBe([$reason]);
})->with([
    'A record to an old host' => [[], ['www.contoh.com' => ['198.51.100.7']], Reason::PointsElsewhere],
    'CNAME to another platform' => [['www.contoh.com' => 'contoh.netlify.app'], ['contoh.netlify.app' => ['198.51.100.8']], Reason::PointsElsewhere],
    'nothing at all' => [[], [], Reason::NoAddress],
    'CNAME loop' => [['www.contoh.com' => 'a.contoh.com', 'a.contoh.com' => 'www.contoh.com'], [], Reason::PointsElsewhere],
]);

test('an apex passes with CNAME flattening: its addresses are the Edge\'s', function (): void {
    $domain = claim('contoh.com', CustomDomainKind::Apex);
    publishDnsFor($this->dns, $domain);

    $result = checkClaim($domain);

    expect($result->passed())->toBeTrue()
        ->and($result->routingMatch)->toBe(CustomDomainRoutingMatch::Addresses);
});

test('an apex whose provider answers with the CNAME itself passes too', function (): void {
    $domain = claim('contoh.com', CustomDomainKind::Apex);
    $this->dns->txt[$domain->txtName()] = [$domain->txtValue()];
    $this->dns->cnames['contoh.com'] = CUSTOM_DOMAIN_TARGET;

    expect(checkClaim($domain)->routingMatch)->toBe(CustomDomainRoutingMatch::Cname);
});

test('an apex with addresses beyond the Edge\'s, or none of them, is refused', function (array $addresses, Reason $reason): void {
    $domain = claim('contoh.com', CustomDomainKind::Apex);
    $this->dns->txt[$domain->txtName()] = [$domain->txtValue()];
    $this->dns->addresses['contoh.com'] = $addresses;

    expect(checkClaim($domain)->reasons)->toBe([$reason]);
})->with([
    'old AAAA left over' => [[CUSTOM_DOMAIN_EDGE_IPV4, '2001:db8::10'], Reason::ExtraAddresses],
    'old host' => [['198.51.100.7'], Reason::PointsElsewhere],
    'IPv6 only' => [['2001:db8::10'], Reason::PointsElsewhere],
]);

test('CAA allows Let\'s Encrypt, or no CA restriction applies', function (array $records): void {
    $domain = claim('www.contoh.com');
    publishDnsFor($this->dns, $domain);
    $this->dns->caa['contoh.com'] = $records;

    expect(checkClaim($domain)->passed())->toBeTrue();
})->with([
    'no CAA' => [[]],
    'letsencrypt allowed' => [[new CaaRecordData(0, 'issue', 'pki.goog'), new CaaRecordData(0, 'issue', 'letsencrypt.org; accounturi=https://acme-v02.api.letsencrypt.org/acme/acct/1')]],
    'only issuewild and iodef' => [[new CaaRecordData(0, 'issuewild', 'pki.goog'), new CaaRecordData(0, 'iodef', 'mailto:x@contoh.com')]],
]);

test('CAA that leaves Let\'s Encrypt out is caught before the domain is active', function (array $records): void {
    $domain = claim('www.contoh.com');
    publishDnsFor($this->dns, $domain);
    $this->dns->caa['contoh.com'] = $records;

    $result = checkClaim($domain);

    expect($result->passed())->toBeFalse()
        ->and($result->caa)->toBeFalse()
        ->and($result->reasons)->toBe([Reason::CaaForbidsIssuer]);
})->with([
    'another CA only' => [[new CaaRecordData(0, 'issue', 'pki.goog')]],
    'nobody' => [[new CaaRecordData(0, 'issue', ';')]],
    'critical unknown tag' => [[new CaaRecordData(128, 'tbs', 'x'), new CaaRecordData(0, 'issue', 'letsencrypt.org')]],
]);

test('the closest CAA record set decides; parents above the registrable domain are not asked', function (): void {
    $domain = claim('app.himpunan.kampus.ac.id');
    publishDnsFor($this->dns, $domain);
    $this->dns->caa['himpunan.kampus.ac.id'] = [new CaaRecordData(0, 'issue', 'letsencrypt.org')];
    $this->dns->caa['kampus.ac.id'] = [new CaaRecordData(0, 'issue', 'pki.goog')];

    expect(checkClaim($domain)->passed())->toBeTrue()
        ->and($this->dns->asked)->not->toContain('CAA ac.id');

    $this->dns->asked = [];
    unset($this->dns->caa['himpunan.kampus.ac.id']);

    expect(checkClaim($domain)->reasons)->toBe([Reason::CaaForbidsIssuer])
        ->and($this->dns->asked)->toContain('CAA kampus.ac.id')
        ->and($this->dns->asked)->not->toContain('CAA ac.id');
});

test('a DNS error fails the part it hit with dns_error', function (): void {
    $domain = claim('www.contoh.com');
    publishDnsFor($this->dns, $domain);
    $this->dns->failing = [$domain->txtName()];

    $result = checkClaim($domain);

    expect($result->reasons)->toBe([Reason::DnsError])
        ->and($result->routing)->toBeTrue();

    $this->dns->failing = [CUSTOM_DOMAIN_TARGET];
    unset($this->dns->cnames['www.contoh.com']);
    $this->dns->addresses['www.contoh.com'] = ['198.51.100.7'];

    expect(checkClaim($domain)->reasons)->toBe([Reason::DnsError]);
});

test('every failing part is reported, ownership first', function (): void {
    $domain = claim('www.contoh.com');
    $this->dns->addresses['www.contoh.com'] = ['198.51.100.7'];
    $this->dns->caa['contoh.com'] = [new CaaRecordData(0, 'issue', 'pki.goog')];

    expect(checkClaim($domain)->reasons)->toBe([Reason::TxtMissing, Reason::PointsElsewhere, Reason::CaaForbidsIssuer]);
});

test('the target comes from the setting when one is given', function (): void {
    config(['sakala.custom_domains.target' => 'Edge.Example.ORG.']);
    $this->dns->addresses['edge.example.org'] = [CUSTOM_DOMAIN_EDGE_IPV4];
    $domain = claim('www.contoh.com');
    $this->dns->txt[$domain->txtName()] = [$domain->txtValue()];
    $this->dns->cnames['www.contoh.com'] = 'edge.example.org';

    expect(checkClaim($domain)->passed())->toBeTrue();
});

test('the fake is the only DNS the tests reach', function (): void {
    expect(app(DnsResolver::class))->toBeInstanceOf(FakeDnsResolver::class);
});
