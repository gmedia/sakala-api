<?php

declare(strict_types=1);

namespace App\Exceptions\CustomDomain;

/** The name is verified on another project; one name serves one project. */
final class CustomDomainTakenException extends CustomDomainRuleException
{
    public const CODE = 'DOMAIN_TAKEN';

    public const STATUS = 409;

    public const CONTEXT = ['hostname' => 'string'];

    public const DESCRIPTION = 'The name (`hostname`, in final form) is active on another project. It must be deleted there before any other project can claim it. A pending claim elsewhere never blocks.';

    public function __construct(
        public readonly string $hostname,
    ) {
        parent::__construct("{$hostname} sudah aktif di proyek lain di Sakala. Satu domain hanya bisa aktif di satu proyek. Kalau proyek itu milikmu, hapus dulu domainnya di sana.");
    }

    /** @return array{hostname: string} */
    public function context(): array
    {
        return ['hostname' => $this->hostname];
    }
}
