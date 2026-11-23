<?php

declare(strict_types=1);

namespace App\Actions\Bucket;

use App\Data\Bucket\BucketCredentialsData;
use App\Exceptions\Bucket\BucketNotReadyException;
use App\Models\ManagedBucket;
use App\Models\User;
use App\Services\Bucket\BucketConnectionEnvironment;
use App\Support\Bucket\BucketAudit;

/**
 * Hands the bucket's key to a person. The key works from the internet, so
 * the reveal is recorded before the secret leaves: if the audit row cannot
 * be written, the caller gets an error and not the secret. The address and
 * user agent are kept only for admins reading a workspace they do not belong
 * to, like database credentials.
 */
final class RevealBucketCredentialsAction
{
    public function __construct(
        private readonly BucketConnectionEnvironment $environment,
    ) {}

    /**
     * @param  array{ip_address: string|null, user_agent: string|null}  $request
     *
     * @throws BucketNotReadyException
     */
    public function handle(User $actor, ManagedBucket $bucket, array $request): BucketCredentialsData
    {
        if (! $bucket->status->isProvisioned()) {
            throw new BucketNotReadyException($bucket->status);
        }

        $member = $bucket->workspace?->roleOf($actor) !== null;

        BucketAudit::record($actor, BucketAudit::CREDENTIALS_VIEWED, $bucket, [
            'by_workspace_member' => $member,
            'access_key_id' => $bucket->access_key_id,
        ], $member ? [] : $request);

        return $this->environment->credentials($bucket->loadMissing('node'));
    }
}
