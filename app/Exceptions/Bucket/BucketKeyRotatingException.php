<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

use App\Models\ManagedBucket;

/**
 * The bucket is in its key uncertain window (BucketKeyWindow): a rotation
 * reached the node and nobody knows yet which secret works, so no deployment
 * receives one and the owner cannot read it.
 */
final class BucketKeyRotatingException extends BucketRuleException
{
    public const CODE = 'BUCKET_KEY_ROTATING';

    public const STATUS = 409;

    public const CONTEXT = ['bucket' => 'array'];

    public const DESCRIPTION = 'The key of the bucket (`bucket`: `id`, `name`) is being rotated and the node has not confirmed which secret works, or the rotation ended `unknown`. Retry once `key_rotation.status` is `completed` or `failed`, or after a new rotation.';

    public function __construct(
        public readonly ManagedBucket $bucket,
    ) {
        parent::__construct(sprintf(
            'Secret key bucket %s sedang diganti dan server penyimpanan belum memastikan key mana yang berlaku. Tunggu sampai penggantian selesai, lalu coba lagi.',
            $bucket->name,
        ));
    }

    /** @return array{bucket: array{id: string, name: string}} */
    public function context(): array
    {
        return ['bucket' => ['id' => $this->bucket->id, 'name' => $this->bucket->name]];
    }
}
