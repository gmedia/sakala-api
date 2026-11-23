<?php

declare(strict_types=1);

namespace App\Actions\Bucket;

use App\Enums\AgentCommandType;
use App\Exceptions\Bucket\BucketBusyException;
use App\Exceptions\Bucket\BucketNotReadyException;
use App\Models\ManagedBucket;
use App\Models\User;
use App\Services\Bucket\BucketCommandService;
use App\Support\Attachment\CredentialRotation;
use App\Support\Bucket\BucketAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asks the storage node for a new secret access key; the access key ID stays
 * the same. The node switches at once, so running containers lose access
 * from that moment, as a leaked key requires. The stored secret stays until
 * the node reports success; then the new one is stored, every attached
 * project shows `redeploy_required`, and, when `$redeployAttached`, the
 * attached projects are redeployed (RedeployAfterCredentialRotationAction).
 * Allowed while locked, because a leaked key must be replaceable. Refused
 * while a CreateBucket is open: a repeated creation resets the secret to the
 * one it carries.
 */
final class RotateBucketKeyAction
{
    public function __construct(
        private readonly BucketCommandService $commands,
    ) {}

    /**
     * @throws BucketNotReadyException
     * @throws BucketBusyException
     */
    public function handle(User $actor, ManagedBucket $bucket, bool $redeployAttached = false): ManagedBucket
    {
        return DB::transaction(function () use ($actor, $bucket, $redeployAttached): ManagedBucket {
            /** @var ManagedBucket $locked */
            $locked = ManagedBucket::query()->whereKey($bucket->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isProvisioned()) {
                throw new BucketNotReadyException($locked->status);
            }

            if ($locked->openCommand(AgentCommandType::RotateBucketKey) !== null) {
                throw BucketBusyException::rotationInProgress();
            }

            if ($locked->openCommand(AgentCommandType::CreateBucket) !== null) {
                throw BucketBusyException::creationInProgress();
            }

            $command = $this->commands->rotateKey(
                $locked,
                Str::random(ManagedBucket::SECRET_LENGTH),
                CredentialRotation::requestContext($actor, $redeployAttached),
            );

            BucketAudit::record($actor, BucketAudit::KEY_ROTATION_REQUESTED, $locked, [
                'command_id' => $command->id,
                'access_key_id' => $locked->access_key_id,
                'redeploy_attached' => $redeployAttached,
            ]);

            return $locked;
        });
    }
}
