<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

/**
 * Storage nodes exist in the region, but none has room left for the quota.
 * The response does not say how much room there is.
 */
final class BucketCapacityUnavailableException extends BucketRuleException
{
    public const CODE = 'BUCKET_CAPACITY_UNAVAILABLE';

    public const STATUS = 409;

    public const DESCRIPTION = 'No storage node of the region has room for the quota right now. Try a smaller quota or later.';

    public function __construct()
    {
        parent::__construct('Server penyimpanan sedang tidak punya ruang untuk kuota sebesar ini. Coba kuota yang lebih kecil, atau coba lagi nanti.');
    }
}
