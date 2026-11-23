<?php

declare(strict_types=1);

namespace App\Services\Bucket;

use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use App\Jobs\Attachment\RedeployAfterCredentialRotationJob;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Support\Attachment\CredentialRotation;
use App\Support\Bucket\BucketAudit;
use App\Support\Bucket\BucketCommandFailure;

/**
 * What a bucket command's result means for its bucket (ADR-021). Called
 * inside the transaction that closes the command, after the command row is
 * locked; the bucket row is locked next, then any attached project. A result
 * never moves a bucket out of a status it left meanwhile: a late lock does
 * not lock a bucket that is being deleted.
 */
final class BucketCommandOutcomeService
{
    public function __construct(
        private readonly BucketCommandService $commands,
    ) {}

    public function completed(AgentNode $agent, AgentCommand $command): void
    {
        $bucket = $this->lockBucket($command);

        if ($bucket === null) {
            return;
        }

        $metadata = ['command_id' => $command->id];

        match ($command->type) {
            AgentCommandType::CreateBucket => $this->completeCreate($agent, $bucket, $metadata),
            AgentCommandType::DeleteBucket => $this->completeDelete($agent, $bucket, $metadata),
            AgentCommandType::RotateBucketKey => $this->completeRotation($agent, $command, $bucket, $metadata),
            AgentCommandType::SetBucketQuota => $this->completeQuota($agent, $command, $bucket, $metadata),
            AgentCommandType::LockBucket => $this->transition($bucket, BucketStatus::Ready, BucketStatus::Locked)
                && $this->audit($agent, BucketAudit::LOCKED, $bucket, $metadata),
            AgentCommandType::UnlockBucket => $this->transition($bucket, BucketStatus::Locked, BucketStatus::Ready)
                && $this->audit($agent, BucketAudit::UNLOCKED, $bucket, $metadata),
            default => null,
        };
    }

    /**
     * A failed or expired command. `$actor` is the node that reported it, or
     * the expiry sweep. A failed delete, rotation, lock, or unlock changes no
     * status: the old key keeps working, and DELETE can be asked again.
     */
    public function failed(AgentNode|string $actor, AgentCommand $command, string $errorCode): void
    {
        $bucket = $this->lockBucket($command);

        if ($bucket === null) {
            return;
        }

        $metadata = ['command_id' => $command->id, 'error_code' => $errorCode];

        match ($command->type) {
            AgentCommandType::CreateBucket => $this->transition($bucket, BucketStatus::Provisioning, BucketStatus::Failed)
                && $this->audit($actor, BucketAudit::PROVISIONING_FAILED, $bucket, $metadata),
            AgentCommandType::DeleteBucket => $this->audit($actor, BucketAudit::DELETE_FAILED, $bucket, $metadata),
            AgentCommandType::RotateBucketKey => $this->failRotation($actor, $command, $bucket, $errorCode, $metadata),
            AgentCommandType::SetBucketQuota => $this->failQuota($actor, $command, $bucket, $metadata),
            AgentCommandType::LockBucket => $this->audit($actor, BucketAudit::LOCK_FAILED, $bucket, $metadata),
            AgentCommandType::UnlockBucket => $this->audit($actor, BucketAudit::UNLOCK_FAILED, $bucket, $metadata),
            default => null,
        };
    }

    /**
     * A first creation makes the bucket ready. A repeated one (buckets:reconcile)
     * on a ready or locked bucket changes nothing: CreateBucket never enables
     * a disabled key, so a locked bucket stays locked.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function completeCreate(AgentNode $agent, ManagedBucket $bucket, array $metadata): bool
    {
        if ($this->transition($bucket, BucketStatus::Provisioning, BucketStatus::Ready)) {
            return $this->audit($agent, BucketAudit::PROVISIONED, $bucket, $metadata);
        }

        return $bucket->status->isProvisioned() && $this->audit($agent, BucketAudit::RECONCILED, $bucket, $metadata);
    }

    /** @param  array<string, mixed>  $metadata */
    private function completeDelete(AgentNode $agent, ManagedBucket $bucket, array $metadata): bool
    {
        if ($bucket->trashed()) {
            return false;
        }

        $bucket->forceFill([
            'status' => BucketStatus::Deleted,
            'secret_access_key' => null,
            'deleted_at' => now(),
        ])->save();

        $this->commands->eraseSecrets($bucket);

        return $this->audit($agent, BucketAudit::DELETED, $bucket, $metadata);
    }

    /**
     * The node now accepts the new secret only: store it, and mark every
     * attached project for a redeploy, since its running containers still
     * have the old one. When the rotation asked for it, the redeploys are
     * queued once this transaction commits.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function completeRotation(AgentNode $agent, AgentCommand $command, ManagedBucket $bucket, array $metadata): bool
    {
        $secret = $this->commands->secretOf($command);

        if ($secret === null || ! $bucket->status->isProvisioned()) {
            return false;
        }

        $bucket->update(['secret_access_key' => $secret]);

        $projects = Project::query()
            ->whereIn('id', $bucket->attachments()->select('project_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($projects as $project) {
            $project->refreshBucketFingerprint();
            $project->save();
        }

        if (CredentialRotation::redeploysAttached($command)) {
            RedeployAfterCredentialRotationJob::dispatch($command->id)->afterCommit();
        }

        return $this->audit($agent, BucketAudit::KEY_ROTATED, $bucket, [
            ...$metadata,
            'project_ids' => $projects->modelKeys(),
        ]);
    }

    /**
     * A rotation that failed for sure keeps the old secret. One whose outcome
     * the node could not confirm (BucketCommandFailure::uncertainRotationCodes())
     * may already have switched the key, so the old secret is not trusted to
     * work either: the same new secret is sent again in a new RotateBucketKey,
     * with the same request context, until the node confirms or refuses it.
     * The stored secret stays the old one and no redeploy is queued until a
     * send completes. After MAX_UNCERTAIN_ATTEMPTS sends, the API stops and
     * `key_rotation.status` shows `unknown`; a new rotation settles it.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function failRotation(AgentNode|string $actor, AgentCommand $command, ManagedBucket $bucket, string $errorCode, array $metadata): bool
    {
        $uncertain = in_array($errorCode, BucketCommandFailure::uncertainRotationCodes(), true);
        $attempt = CredentialRotation::attempt($command);

        if ($uncertain && $bucket->status->isProvisioned() && $attempt < CredentialRotation::MAX_UNCERTAIN_ATTEMPTS) {
            $retry = $this->commands->resendRotation($bucket, $command);

            return $this->audit($actor, BucketAudit::KEY_ROTATION_RESENT, $bucket, [
                ...$metadata,
                'resent_command_id' => $retry->id,
                'attempt' => $attempt + 1,
            ]);
        }

        return $this->audit($actor, BucketAudit::KEY_ROTATION_FAILED, $bucket, [
            ...$metadata,
            'outcome_unknown' => $uncertain,
            'attempt' => $attempt,
        ]);
    }

    /**
     * The node applies the new quota: it becomes both the applied quota and
     * the reservation, which a decrease releases only now.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function completeQuota(AgentNode $agent, AgentCommand $command, ManagedBucket $bucket, array $metadata): bool
    {
        $quota = $this->commands->quotaOf($command);

        if ($quota === null || $bucket->trashed()) {
            return false;
        }

        $from = $bucket->quota_mb;
        $bucket->update(['quota_mb' => $quota, 'reserved_quota_mb' => $quota]);

        return $this->audit($agent, BucketAudit::QUOTA_CHANGED, $bucket, [...$metadata, 'from_mb' => $from, 'to_mb' => $quota]);
    }

    /**
     * The node kept the old quota, so the reservation an increase took is
     * released.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function failQuota(AgentNode|string $actor, AgentCommand $command, ManagedBucket $bucket, array $metadata): bool
    {
        if (! $bucket->trashed()) {
            $bucket->update(['reserved_quota_mb' => $bucket->quota_mb]);
        }

        return $this->audit($actor, BucketAudit::QUOTA_CHANGE_FAILED, $bucket, [
            ...$metadata,
            'quota_mb' => $this->commands->quotaOf($command),
        ]);
    }

    private function transition(ManagedBucket $bucket, BucketStatus $from, BucketStatus $to): bool
    {
        if ($bucket->status !== $from) {
            return false;
        }

        $bucket->update(['status' => $to]);

        return true;
    }

    /** @param  array<string, mixed>  $metadata */
    private function audit(AgentNode|string $actor, string $action, ManagedBucket $bucket, array $metadata): bool
    {
        BucketAudit::record($actor, $action, $bucket, $metadata);

        return true;
    }

    private function lockBucket(AgentCommand $command): ?ManagedBucket
    {
        if ($command->bucket_id === null) {
            return null;
        }

        return ManagedBucket::withTrashed()
            ->whereKey($command->bucket_id)
            ->lockForUpdate()
            ->first();
    }
}
