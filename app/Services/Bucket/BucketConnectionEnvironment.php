<?php

declare(strict_types=1);

namespace App\Services\Bucket;

use App\Data\Bucket\BucketCredentialsData;
use App\Data\Bucket\ObjectStorageData;
use App\Exceptions\Bucket\BucketEndpointUnavailableException;
use App\Exceptions\Bucket\BucketKeyRotatingException;
use App\Exceptions\Bucket\BucketNotReadyException;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Support\Bucket\BucketKeyWindow;

/**
 * The variables a project receives from its attached bucket (ADR-021): what
 * AWS SDKs and Laravel's `s3` disk read. They are built when a deployment is
 * created and never stored as the project's own variables, and a variable
 * the project sets itself with the same name wins.
 *
 * `AWS_ENDPOINT_URL_S3` is injected instead of the global `AWS_ENDPOINT_URL`
 * (decision R1): current SDKs read the global one for every AWS service, so
 * an app that also talks to SES or SQS with its own keys would send those
 * calls to the bucket endpoint. `FILESYSTEM_DISK` and `AWS_URL` are never
 * injected: the first changes how the app behaves beyond the connection,
 * the second only serves public buckets.
 */
final class BucketConnectionEnvironment
{
    /** Read by current AWS SDKs for S3 only. */
    public const AWS_ENDPOINT_URL_S3 = 'AWS_ENDPOINT_URL_S3';

    /** Read by Laravel's `s3` disk. */
    public const AWS_ENDPOINT = 'AWS_ENDPOINT';

    public const AWS_USE_PATH_STYLE_ENDPOINT = 'AWS_USE_PATH_STYLE_ENDPOINT';

    /** Read by Laravel and boto3. */
    public const AWS_DEFAULT_REGION = 'AWS_DEFAULT_REGION';

    /** Read by the JavaScript SDK v3. */
    public const AWS_REGION = 'AWS_REGION';

    public const AWS_BUCKET = 'AWS_BUCKET';

    public const AWS_ACCESS_KEY_ID = 'AWS_ACCESS_KEY_ID';

    public const AWS_SECRET_ACCESS_KEY = 'AWS_SECRET_ACCESS_KEY';

    /** The instance only serves path-style requests (`https://s3.<domain>/<bucket>/<key>`). */
    public const PATH_STYLE = 'true';

    /** @return list<string> Every variable name an attached bucket sets, in a stable order. */
    public static function names(): array
    {
        return [
            self::AWS_ENDPOINT_URL_S3,
            self::AWS_ENDPOINT,
            self::AWS_USE_PATH_STYLE_ENDPOINT,
            self::AWS_DEFAULT_REGION,
            self::AWS_REGION,
            self::AWS_BUCKET,
            self::AWS_ACCESS_KEY_ID,
            self::AWS_SECRET_ACCESS_KEY,
        ];
    }

    /**
     * The bucket's connection details with its secret. Null endpoint, region,
     * and variables while its node has not reported its object storage.
     */
    public function credentials(ManagedBucket $bucket): BucketCredentialsData
    {
        $storage = $bucket->node?->objectStorage();

        return new BucketCredentialsData(
            endpoint: $storage?->endpoint,
            region: $storage?->region,
            bucket: $bucket->s3_bucket,
            accessKeyId: $bucket->access_key_id,
            secretAccessKey: (string) $bucket->secret_access_key,
            variables: $storage === null ? null : $this->variables($bucket, $storage),
        );
    }

    /**
     * The variables the project's next deployment receives from its attached
     * bucket, without those the project sets itself. Empty without an
     * attached bucket. Every way a deployment is created reads them, so this
     * is also where a deployment is refused while the bucket cannot be used.
     * A locked bucket still injects: the app gets AccessDenied, as with a
     * locked database.
     *
     * @return array<string, string>
     *
     * @throws BucketNotReadyException while the attached bucket is still being created, or failed to be.
     * @throws BucketEndpointUnavailableException when the storage node has not reported its endpoint.
     * @throws BucketKeyRotatingException while nobody knows which secret works (BucketKeyWindow).
     */
    public function forProject(Project $project): array
    {
        $bucket = $project->buckets()->with('node')->first();

        if ($bucket === null) {
            return [];
        }

        // Only a bucket created for this project is attached before it is
        // ready; deploying now would start an app whose bucket is not there.
        if (! $bucket->status->isProvisioned()) {
            throw new BucketNotReadyException($bucket->status);
        }

        $storage = $bucket->node?->objectStorage();

        if ($storage === null) {
            throw new BucketEndpointUnavailableException($bucket);
        }

        if (BucketKeyWindow::isOpen($bucket)) {
            throw new BucketKeyRotatingException($bucket);
        }

        $own = $project->environmentVariables()->pluck('key')->all();

        return array_diff_key($this->variables($bucket, $storage), array_flip($own));
    }

    /**
     * Names the project sets itself among the ones its bucket would set:
     * those keep the project's value.
     *
     * @param  list<string>  $projectKeys
     * @return list<string>
     */
    public function overridden(array $projectKeys): array
    {
        return array_values(array_intersect(self::names(), $projectKeys));
    }

    /** @return array<string, string> In the order of names(). */
    private function variables(ManagedBucket $bucket, ObjectStorageData $storage): array
    {
        return [
            self::AWS_ENDPOINT_URL_S3 => $storage->endpoint,
            self::AWS_ENDPOINT => $storage->endpoint,
            self::AWS_USE_PATH_STYLE_ENDPOINT => self::PATH_STYLE,
            self::AWS_DEFAULT_REGION => $storage->region,
            self::AWS_REGION => $storage->region,
            self::AWS_BUCKET => $bucket->s3_bucket,
            self::AWS_ACCESS_KEY_ID => $bucket->access_key_id,
            self::AWS_SECRET_ACCESS_KEY => (string) $bucket->secret_access_key,
        ];
    }
}
