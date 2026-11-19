<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

/**
 * No node with the `managed-postgres` capability is allowed work in the
 * requested region (or anywhere, without one), so the database would wait
 * forever. A database node that is only offline is waited for.
 */
final class DatabaseRegionUnavailableException extends DatabaseRuleException
{
    public const CODE = 'DATABASE_REGION_UNAVAILABLE';

    public const STATUS = 409;

    public const CONTEXT = ['region' => 'nullable-string'];

    public const DESCRIPTION = 'No database node is allowed work in the region (`region`), or anywhere when `region` is null.';

    public function __construct(
        public readonly ?string $region,
    ) {
        parent::__construct($region === null
            ? 'Belum ada server database yang bisa menerima database baru. Coba lagi nanti, atau hubungi operator Sakala.'
            : sprintf('Belum ada server database di region %s. Pilih region lain, atau kosongkan agar database dibuat di region mana pun.', $region));
    }

    /** @return array{region: string|null} */
    public function context(): array
    {
        return ['region' => $this->region];
    }
}
