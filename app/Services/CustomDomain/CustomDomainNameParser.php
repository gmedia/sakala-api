<?php

declare(strict_types=1);

namespace App\Services\CustomDomain;

use App\Data\CustomDomain\ParsedCustomDomainData;
use App\Enums\CustomDomainKind;
use App\Exceptions\CustomDomain\InvalidCustomDomainNameException;
use Pdp\Domain;
use Pdp\Rules;
use Pdp\SyntaxError;

/**
 * Turns what a user typed into the final form of a custom domain, or refuses
 * it with a reason in Indonesian (ADR-024). The final form is what the agent
 * and the Edge accept without normalizing again: lowercase ASCII LDH labels,
 * IDN as valid punycode, at least two labels, at most 253 characters, no
 * scheme, port, path, wildcard, or trailing dot, not an IP address, not
 * under a forbidden zone or the project domain, and not a public suffix.
 *
 * IDN conversion and the Public Suffix List come from
 * jeremykendall/php-domain-parser, with the list file shipped in
 * resources/data (CustomDomainPolicy::publicSuffixListPath()). The library
 * accepts some names DNS hosts do not (underscores, labels starting with a
 * hyphen, `*`, `xn--` labels that are not punycode), so those are checked
 * here.
 */
final class CustomDomainNameParser
{
    /** One LDH label: letters, digits, inner hyphens, 1 to 63 characters, lowercase. */
    private const LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /** A trailing `:port` a user may paste with the name. */
    private const PORT_PATTERN = '/:\d+$/';

    /**
     * Parsed lists by path, kept for the life of the process: the file has
     * about 17,000 lines and changes only with a release.
     *
     * @var array<string, Rules>
     */
    private static array $rulesByPath = [];

    public function __construct(
        private readonly CustomDomainPolicy $policy,
    ) {}

    /** @throws InvalidCustomDomainNameException */
    public function parse(string $input): ParsedCustomDomainData
    {
        $name = $this->strip($input);

        if ($name === '') {
            throw new InvalidCustomDomainNameException('Isi nama domain, misalnya www.contoh.com.');
        }

        if (str_contains($name, '*')) {
            throw new InvalidCustomDomainNameException('Wildcard seperti *.contoh.com belum didukung. Tambahkan setiap nama satu per satu, misalnya www.contoh.com.');
        }

        if (filter_var(trim($name, '[]'), FILTER_VALIDATE_IP) !== false) {
            throw new InvalidCustomDomainNameException('Isi nama domain, bukan alamat IP.');
        }

        $hostname = $this->toAscii($name);
        $labels = explode('.', $hostname);

        if (count($labels) < 2) {
            throw new InvalidCustomDomainNameException('Nama domain harus punya minimal dua bagian, misalnya contoh.com.');
        }

        if (strlen($hostname) > CustomDomainPolicy::MAX_HOSTNAME_LENGTH) {
            throw new InvalidCustomDomainNameException(sprintf('Nama domain paling panjang %d karakter.', CustomDomainPolicy::MAX_HOSTNAME_LENGTH));
        }

        foreach ($labels as $label) {
            $this->ensureValidLabel($label);
        }

        $this->ensureNotForbidden($hostname);

        return new ParsedCustomDomainData($hostname, $this->kindOf($hostname));
    }

    /**
     * The registrable domain of a name in final form (`contoh.com` for
     * `www.contoh.com`), or null when the list does not know its suffix.
     */
    public function registrableDomain(string $hostname): ?string
    {
        try {
            $resolved = $this->rules()->resolve(Domain::fromIDNA2008($hostname));
        } catch (SyntaxError) {
            return null;
        }

        return $resolved->suffix()->isKnown() ? $resolved->registrableDomain()->toString() : null;
    }

    /** Lowercase, without spaces, scheme, path, port, or trailing dot. */
    private function strip(string $input): string
    {
        $name = strtolower(trim($input));

        if (($scheme = strpos($name, '://')) !== false) {
            $name = substr($name, $scheme + 3);
        }

        $name = (string) preg_replace('/[\/?#].*$/', '', $name);
        $name = (string) preg_replace(self::PORT_PATTERN, '', $name);

        return rtrim($name, '.');
    }

    /** @throws InvalidCustomDomainNameException */
    private function toAscii(string $name): string
    {
        try {
            return strtolower(Domain::fromIDNA2008($name)->toAscii()->toString());
        } catch (SyntaxError) {
            throw new InvalidCustomDomainNameException('Nama domain ini tidak valid. Pakai huruf, angka, dan tanda hubung, dipisah titik, misalnya www.contoh.com.');
        }
    }

    /** @throws InvalidCustomDomainNameException */
    private function ensureValidLabel(string $label): void
    {
        if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
            throw new InvalidCustomDomainNameException('Setiap bagian nama domain hanya boleh berisi huruf, angka, dan tanda hubung, tidak diawali atau diakhiri tanda hubung, dan paling panjang 63 karakter.');
        }

        if (str_starts_with($label, 'xn--') && ! $this->isValidPunycode($label)) {
            throw new InvalidCustomDomainNameException('Bagian nama domain yang diawali xn-- bukan nama internasional yang valid.');
        }
    }

    /** A punycode label must decode, and encode back to itself (intl, UTS #46). */
    private function isValidPunycode(string $label): bool
    {
        $info = [];
        $unicode = idn_to_utf8($label, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46, $info);

        if ($unicode === false || ($info['errors'] ?? 1) !== 0) {
            return false;
        }

        return idn_to_ascii($unicode, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) === $label;
    }

    /** @throws InvalidCustomDomainNameException */
    private function ensureNotForbidden(string $hostname): void
    {
        foreach ($this->policy->forbiddenZones() as $zone) {
            if ($hostname === $zone || str_ends_with($hostname, '.'.$zone)) {
                throw new InvalidCustomDomainNameException("Domain di bawah {$zone} milik Sakala dan tidak bisa dipakai sebagai custom domain. Alamat proyekmu di Sakala sudah aktif tanpa perlu ditambahkan.");
            }
        }
    }

    /** @throws InvalidCustomDomainNameException */
    private function kindOf(string $hostname): CustomDomainKind
    {
        if ($this->isPublicSuffix($hostname)) {
            throw new InvalidCustomDomainNameException("{$hostname} adalah akhiran domain publik yang dipakai banyak orang, bukan domain milik satu orang. Pakai domain yang kamu daftarkan di bawahnya, misalnya nama.{$hostname}.");
        }

        $resolved = $this->rules()->resolve(Domain::fromIDNA2008($hostname));

        if (! $resolved->suffix()->isKnown()) {
            throw new InvalidCustomDomainNameException('Akhiran domain ini tidak dikenal. Pakai domain yang sudah kamu daftarkan di registrar, misalnya contoh.com atau nama.my.id.');
        }

        return $resolved->registrableDomain()->toString() === $hostname ? CustomDomainKind::Apex : CustomDomainKind::Subdomain;
    }

    /**
     * Whether the list names `$hostname` itself as a suffix (`ac.id`,
     * `my.id`, `github.io`): a name one label below it then has it as its
     * suffix. The library reports such a name as having no known suffix.
     */
    private function isPublicSuffix(string $hostname): bool
    {
        try {
            return $this->rules()->resolve(Domain::fromIDNA2008('x.'.$hostname))->suffix()->toString() === $hostname;
        } catch (SyntaxError) {
            return false;
        }
    }

    private function rules(): Rules
    {
        $path = $this->policy->publicSuffixListPath();

        return self::$rulesByPath[$path] ??= Rules::fromPath($path);
    }
}
