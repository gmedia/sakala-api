<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

/**
 * No node with the `managed-s3` capability is allowed work in the requested
 * region (or anywhere, without one), so the bucket would wait forever. A
 * storage node that is only offline is waited for.
 */
final class BucketRegionUnavailableException extends BucketRuleException
{
    public const CODE = 'BUCKET_REGION_UNAVAILABLE';

    public const STATUS = 409;

    public const CONTEXT = ['region' => 'nullable-string'];

    public const DESCRIPTION = 'No storage node is allowed work in the region (`region`), or anywhere when `region` is null.';

    public function __construct(
        public readonly ?string $region,
    ) {
        parent::__construct($region === null
            ? 'Belum ada server penyimpanan yang bisa menerima bucket baru. Coba lagi nanti, atau hubungi operator Sakala.'
            : sprintf('Belum ada server penyimpanan di region %s. Pilih region lain, atau kosongkan agar bucket dibuat di region mana pun.', $region));
    }

    /** @return array{region: string|null} */
    public function context(): array
    {
        return ['region' => $this->region];
    }
}
