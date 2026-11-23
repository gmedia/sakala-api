<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Bucket;

use App\Data\Bucket\BucketCredentialsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How to reach one managed bucket with its key: the same values attached
 * projects receive. The key works from the internet.
 *
 * @property BucketCredentialsData $resource
 */
final class BucketCredentialsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** Public S3 endpoint, for example `https://s3.sakala.app`; `null` until the storage node reports it. */
            'endpoint' => $this->resource->endpoint,
            /** S3 signing region, for example `us-east-1`; `null` until the storage node reports it. */
            'region' => $this->resource->region,
            /** Bucket name on the storage node (`AWS_BUCKET`). */
            'bucket' => $this->resource->bucket,
            'access_key_id' => $this->resource->accessKeyId,
            'secret_access_key' => $this->resource->secretAccessKey,
            /** Always `true`: the endpoint serves path-style requests only. */
            'path_style' => true,
            /**
             * Exactly the variables an attached project receives, in this order: `AWS_ENDPOINT_URL_S3`,
             * `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`, `AWS_DEFAULT_REGION`, `AWS_REGION`, `AWS_BUCKET`,
             * `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`. `null` until the storage node reports its endpoint.
             */
            'variables' => $this->resource->variables,
        ];
    }
}
