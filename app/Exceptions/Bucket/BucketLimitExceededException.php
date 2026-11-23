<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

/** The workspace already holds as many managed buckets as its limit profile allows. */
final class BucketLimitExceededException extends BucketRuleException
{
    public const CODE = 'BUCKET_LIMIT_EXCEEDED';

    public const STATUS = 422;

    public const CONTEXT = ['limit' => 'integer', 'current' => 'integer'];

    public const DESCRIPTION = 'The workspace holds as many buckets as its limit allows (`limit`, `current`). `limit` 0 means the workspace may not create buckets yet.';

    public function __construct(
        public readonly int $limit,
        public readonly int $current,
    ) {
        parent::__construct($limit === 0
            ? 'Workspace ini belum bisa membuat bucket. Minta admin mengaktifkannya lewat profil batas workspace.'
            : sprintf('Workspace ini sudah punya %d bucket, dan itu batasnya. Hapus bucket yang tidak dipakai untuk membuat yang baru.', $current));
    }

    /** @return array{limit: int, current: int} */
    public function context(): array
    {
        return ['limit' => $this->limit, 'current' => $this->current];
    }
}
