<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

use App\Models\ManagedBucket;

/**
 * A deployment needs the variables of its attached bucket, and the storage
 * node has not reported its endpoint (`metadata.object_storage`). Deploying
 * without them would start an application that cannot reach its files.
 */
final class BucketEndpointUnavailableException extends BucketRuleException
{
    public const CODE = 'BUCKET_ENDPOINT_UNAVAILABLE';

    public const STATUS = 409;

    public const CONTEXT = ['bucket' => 'array'];

    public const DESCRIPTION = 'The node of the attached bucket (`bucket`: `id`, `name`) has not reported its endpoint yet, so the bucket variables cannot be built.';

    public function __construct(
        public readonly ManagedBucket $bucket,
    ) {
        parent::__construct(sprintf(
            'Server penyimpanan untuk bucket %s belum melaporkan alamatnya, jadi proyek ini belum bisa di-deploy dengan bucket terpasang. Coba lagi beberapa saat lagi, atau lepas bucket-nya dulu.',
            $bucket->name,
        ));
    }

    /** @return array{bucket: array{id: string, name: string}} */
    public function context(): array
    {
        return ['bucket' => ['id' => $this->bucket->id, 'name' => $this->bucket->name]];
    }
}
