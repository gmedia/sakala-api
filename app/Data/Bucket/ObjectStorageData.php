<?php

declare(strict_types=1);

namespace App\Data\Bucket;

/**
 * What a storage node reported about its object storage instance in its
 * last heartbeat (`metadata.object_storage`, ADR-021).
 */
final readonly class ObjectStorageData
{
    public function __construct(
        /** Public S3 endpoint, for example `https://s3.sakala.app`. */
        public string $endpoint,
        /** S3 signing region, for example `us-east-1`. */
        public string $region,
        /** MB the node may allocate to bucket quotas in total. */
        public int $capacityMb,
    ) {}
}
