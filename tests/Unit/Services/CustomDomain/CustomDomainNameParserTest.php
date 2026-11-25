<?php

declare(strict_types=1);

use App\Enums\CustomDomainKind;
use App\Exceptions\CustomDomain\InvalidCustomDomainNameException;
use App\Services\CustomDomain\CustomDomainNameParser;

beforeEach(function (): void {
    enableCustomDomains();
    config(['sakala.custom_domains.forbidden_zones' => 'sakala.app,sakala.dev,localhost']);
});

function parseDomain(string $input): array
{
    $parsed = app(CustomDomainNameParser::class)->parse($input);

    return [$parsed->hostname, $parsed->kind];
}

test('a name is lowercased and stripped of a scheme, path, port, and trailing dot', function (string $input, string $hostname, CustomDomainKind $kind): void {
    expect(parseDomain($input))->toBe([$hostname, $kind]);
})->with([
    'subdomain' => ['www.contoh.com', 'www.contoh.com', CustomDomainKind::Subdomain],
    'apex' => ['contoh.com', 'contoh.com', CustomDomainKind::Apex],
    'uppercase and trailing dot' => ['WWW.Contoh.COM.', 'www.contoh.com', CustomDomainKind::Subdomain],
    'pasted URL' => ['https://www.contoh.com:8443/halaman?x=1', 'www.contoh.com', CustomDomainKind::Subdomain],
    'campus apex' => ['kampus.ac.id', 'kampus.ac.id', CustomDomainKind::Apex],
    'campus subdomain' => ['himpunan.kampus.ac.id', 'himpunan.kampus.ac.id', CustomDomainKind::Subdomain],
    'my.id apex' => ['budi.my.id', 'budi.my.id', CustomDomainKind::Apex],
    'two-level suffix' => ['a.b.example.co.uk', 'a.b.example.co.uk', CustomDomainKind::Subdomain],
    'IDN to punycode' => ['bücher.de', 'xn--bcher-kva.de', CustomDomainKind::Apex],
    'valid punycode kept' => ['www.xn--bcher-kva.de', 'www.xn--bcher-kva.de', CustomDomainKind::Subdomain],
]);

test('names that cannot be a custom domain are refused with a reason', function (string $input, string $reason): void {
    expect(fn () => parseDomain($input))->toThrow(InvalidCustomDomainNameException::class, $reason);
})->with([
    'empty' => ['  ', 'Isi nama domain'],
    'IPv4' => ['103.217.211.47', 'bukan alamat IP'],
    'IPv6' => ['[2001:db8::1]', 'bukan alamat IP'],
    'wildcard' => ['*.contoh.com', 'Wildcard'],
    'one label' => ['contoh', 'minimal dua bagian'],
    'underscore' => ['a_b.contoh.com', 'huruf, angka, dan tanda hubung'],
    'leading hyphen' => ['-a.contoh.com', 'huruf, angka, dan tanda hubung'],
    'label over 63' => [str_repeat('a', 64).'.com', 'tidak valid'],
    'longer than 253' => [implode('.', array_fill(0, 50, 'abcde')).'.com', 'paling panjang 253'],
    'bad punycode' => ['xn--zz.com', 'xn--'],
    'project domain' => ['portfolio.sakala.app', 'milik Sakala'],
    'project domain itself' => ['sakala.app', 'milik Sakala'],
    'platform zone' => ['api.sakala.dev', 'milik Sakala'],
    'public suffix ac.id' => ['ac.id', 'akhiran domain publik'],
    'public suffix my.id' => ['my.id', 'akhiran domain publik'],
    'unknown suffix' => ['foo.notatld', 'tidak dikenal'],
]);

test('the configured project domain and forbidden zones are refused too', function (): void {
    config(['sakala.project.default_domain' => 'run.example.org', 'sakala.custom_domains.forbidden_zones' => 'internal.example.net']);

    expect(fn () => parseDomain('foo.run.example.org'))->toThrow(InvalidCustomDomainNameException::class)
        ->and(fn () => parseDomain('x.internal.example.net'))->toThrow(InvalidCustomDomainNameException::class)
        ->and(parseDomain('foo.sakala.dev'))->toBe(['foo.sakala.dev', CustomDomainKind::Subdomain]);
});
