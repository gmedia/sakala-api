<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

/**
 * An admin locked the bucket: its key is disabled. Attaching it would hand a
 * project a key that cannot read or write.
 */
final class BucketLockedException extends BucketRuleException
{
    public const CODE = 'BUCKET_LOCKED';

    public const STATUS = 409;

    public const DESCRIPTION = 'An admin locked the bucket, so it cannot be attached to a project.';

    public function __construct()
    {
        parent::__construct('Bucket ini dikunci admin Sakala, jadi belum bisa dipasang ke proyek. Hubungi admin untuk membukanya.');
    }
}
