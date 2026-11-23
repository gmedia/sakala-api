<?php

declare(strict_types=1);

namespace App\Services\Bucket;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\ManagedBucket;
use App\Services\Agent\AgentCommandPayloadMaterializer;
use App\Support\Attachment\CredentialRotation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Creates the agent commands for one managed bucket (ADR-021). Every command
 * is pinned to the node that hosts the bucket and waits for it without a
 * deadline. A secret access key travels encrypted in `agent_commands.payload`
 * and is decrypted only on the wire to that node
 * (AgentCommandPayloadMaterializer).
 *
 * Payloads (additive within protocol revision 4; sakala-agent
 * `examples/commands/bucket-commands.json`):
 *
 * - CreateBucket: `bucket`, `access_key_id`, `secret_access_key`, `quota_mb`
 * - DeleteBucket: `bucket`, `access_key_id`
 * - RotateBucketKey: `bucket`, `access_key_id`, `secret_access_key`
 * - SetBucketQuota: `bucket`, `quota_mb`
 * - LockBucket, UnlockBucket: `bucket`, `access_key_id`
 */
final class BucketCommandService
{
    /** Payload key of the bucket name on the node. */
    public const BUCKET_KEY = 'bucket';

    /** Payload key of the access key ID, also the RustFS user name. */
    public const ACCESS_KEY_ID_KEY = 'access_key_id';

    /** Payload key of the quota in MB. */
    public const QUOTA_KEY = 'quota_mb';

    public function create(ManagedBucket $bucket, string $secret): AgentCommand
    {
        return $this->dispatch($bucket, AgentCommandType::CreateBucket, [
            ...$this->identity($bucket),
            AgentCommandPayloadMaterializer::SECRET_KEY => Crypt::encryptString($secret),
            self::QUOTA_KEY => $bucket->quota_mb,
        ]);
    }

    public function delete(ManagedBucket $bucket): AgentCommand
    {
        return $this->dispatch($bucket, AgentCommandType::DeleteBucket, $this->identity($bucket));
    }

    /**
     * @param  array<string, mixed>|null  $requestContext  What the control plane does once the node reports
     *                                                     (CredentialRotation); never sent to the node.
     */
    public function rotateKey(ManagedBucket $bucket, string $secret, ?array $requestContext = null): AgentCommand
    {
        return $this->dispatch($bucket, AgentCommandType::RotateBucketKey, [
            ...$this->identity($bucket),
            AgentCommandPayloadMaterializer::SECRET_KEY => Crypt::encryptString($secret),
        ], $requestContext);
    }

    /**
     * Send a RotateBucketKey again with the same new secret, after an outcome
     * the node could not confirm: the agent's rotation is idempotent, so the
     * same secret either takes effect now or already had. The control-plane
     * context goes along, with the next attempt number, and the node sees it
     * only after CredentialRotation::resendDelaySeconds().
     */
    public function resendRotation(ManagedBucket $bucket, AgentCommand $previous): AgentCommand
    {
        /** @var array<string, string|int> $payload */
        $payload = $previous->payload ?? [];
        $attempt = CredentialRotation::attempt($previous) + 1;

        return $this->dispatch($bucket, AgentCommandType::RotateBucketKey, $payload, [
            ...($previous->request_context ?? []),
            CredentialRotation::ATTEMPT_KEY => $attempt,
        ], now()->addSeconds(CredentialRotation::resendDelaySeconds($attempt)));
    }

    public function setQuota(ManagedBucket $bucket, int $quotaMb): AgentCommand
    {
        return $this->dispatch($bucket, AgentCommandType::SetBucketQuota, [
            self::BUCKET_KEY => $bucket->s3_bucket,
            self::QUOTA_KEY => $quotaMb,
        ]);
    }

    public function lock(ManagedBucket $bucket): AgentCommand
    {
        return $this->dispatch($bucket, AgentCommandType::LockBucket, $this->identity($bucket));
    }

    public function unlock(ManagedBucket $bucket): AgentCommand
    {
        return $this->dispatch($bucket, AgentCommandType::UnlockBucket, $this->identity($bucket));
    }

    /**
     * The secret a CreateBucket or RotateBucketKey carries, or null for
     * another command, or one whose secret was already erased.
     */
    public function secretOf(AgentCommand $command): ?string
    {
        if (! $command->type->carriesBucketSecret()) {
            return null;
        }

        $encrypted = $command->payload[AgentCommandPayloadMaterializer::SECRET_KEY] ?? null;

        return is_string($encrypted) ? Crypt::decryptString($encrypted) : null;
    }

    /** The quota a SetBucketQuota carries, or null for another command. */
    public function quotaOf(AgentCommand $command): ?int
    {
        $quota = $command->payload[self::QUOTA_KEY] ?? null;

        return $command->type === AgentCommandType::SetBucketQuota && is_int($quota) ? $quota : null;
    }

    /**
     * Remove every secret copy from the bucket's commands, once the bucket is
     * deleted and none of them can run again.
     */
    public function eraseSecrets(ManagedBucket $bucket): void
    {
        $bucket->commands()
            ->whereIn('type', [AgentCommandType::CreateBucket, AgentCommandType::RotateBucketKey])
            ->get()
            ->each(static function (AgentCommand $command): void {
                $payload = $command->payload ?? [];
                unset($payload[AgentCommandPayloadMaterializer::SECRET_KEY]);
                $command->update(['payload' => $payload]);
            });
    }

    /** @return array{bucket: string, access_key_id: string} */
    private function identity(ManagedBucket $bucket): array
    {
        return [
            self::BUCKET_KEY => $bucket->s3_bucket,
            self::ACCESS_KEY_ID_KEY => $bucket->access_key_id,
        ];
    }

    /**
     * @param  array<string, string|int>  $payload
     * @param  array<string, mixed>|null  $requestContext
     */
    private function dispatch(ManagedBucket $bucket, AgentCommandType $type, array $payload, ?array $requestContext = null, ?CarbonInterface $availableAt = null): AgentCommand
    {
        return AgentCommand::query()->create([
            'bucket_id' => $bucket->id,
            'agent_node_id' => $bucket->node_id,
            'type' => $type,
            'status' => AgentCommandStatus::Pending,
            'payload' => $payload,
            'request_context' => $requestContext,
            'idempotency_key' => (string) Str::uuid(),
            'available_at' => $availableAt ?? now(),
            // Waits for the bucket's node however long it is offline: the
            // bucket cannot move to another node.
            'expires_at' => null,
        ]);
    }
}
