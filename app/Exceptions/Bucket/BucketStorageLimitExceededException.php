<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

/**
 * Creating a bucket or raising its quota would push the MB the workspace's
 * buckets reserve above `max_bucket_storage_mb`.
 */
final class BucketStorageLimitExceededException extends BucketRuleException
{
    public const CODE = 'BUCKET_STORAGE_LIMIT_EXCEEDED';

    public const STATUS = 422;

    public const CONTEXT = ['limit_mb' => 'integer', 'current_mb' => 'integer', 'requested_mb' => 'integer'];

    public const DESCRIPTION = 'The bucket quota would push the MB reserved by the workspace\'s buckets above its storage limit. `limit_mb` is the limit, `current_mb` what the other buckets reserve (without this one), `requested_mb` the quota asked for.';

    public function __construct(
        public readonly int $limitMb,
        public readonly int $currentMb,
        public readonly int $requestedMb,
    ) {
        parent::__construct($limitMb === 0
            ? 'Workspace ini belum punya jatah penyimpanan bucket. Minta admin mengaktifkannya lewat profil batas workspace.'
            : sprintf(
                'Kuota %d MB melebihi sisa jatah penyimpanan bucket workspace ini (%d dari %d MB sudah dialokasikan). Kecilkan kuotanya, kecilkan atau hapus bucket lain, atau minta admin menaikkan jatahnya.',
                $requestedMb,
                $currentMb,
                $limitMb,
            ));
    }

    /** @return array{limit_mb: int, current_mb: int, requested_mb: int} */
    public function context(): array
    {
        return ['limit_mb' => $this->limitMb, 'current_mb' => $this->currentMb, 'requested_mb' => $this->requestedMb];
    }
}
