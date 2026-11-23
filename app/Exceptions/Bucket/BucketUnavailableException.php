<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

/**
 * Creating a bucket for a project while no storage node can take one right
 * now (`buckets.available` is false). A bucket created on its own still
 * waits for its node.
 */
final class BucketUnavailableException extends BucketRuleException
{
    public const CODE = 'BUCKET_UNAVAILABLE';

    public const STATUS = 409;

    public const DESCRIPTION = 'A bucket for a project (`project_id`) is refused while no storage node can take a new bucket (`buckets.available` in `GET app/pilot-limits` is false).';

    public function __construct()
    {
        parent::__construct('Server penyimpanan sedang tidak tersedia, jadi bucket untuk proyek ini belum bisa dibuat. Coba lagi nanti.');
    }
}
