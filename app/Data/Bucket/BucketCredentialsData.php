<?php

declare(strict_types=1);

namespace App\Data\Bucket;

/**
 * How to reach one managed bucket with its key, as the credentials endpoint
 * hands it to the owner.
 */
final readonly class BucketCredentialsData
{
    /**
     * @param  array<string, string>|null  $variables  Exactly what attached projects receive; null while the endpoint is unknown.
     */
    public function __construct(
        public ?string $endpoint,
        public ?string $region,
        public string $bucket,
        public string $accessKeyId,
        public string $secretAccessKey,
        public ?array $variables,
    ) {}
}
