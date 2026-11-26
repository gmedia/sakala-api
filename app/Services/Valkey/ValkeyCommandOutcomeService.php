<?php

declare(strict_types=1);

namespace App\Services\Valkey;

use App\Enums\AgentCommandType;
use App\Enums\ProjectStatus;
use App\Enums\ValkeyLockSource;
use App\Enums\ValkeyStatus;
use App\Jobs\Attachment\RedeployAfterCredentialRotationJob;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Support\Attachment\CredentialRotation;
use App\Support\Valkey\ValkeyAudit;
use App\Support\Valkey\ValkeyCommandFailure;

/**
 * What a Valkey command's result means for its store (ADR-023). Called
 * inside the transaction that closes the command, after the command row is
 * locked; the store row is locked next, then any attached project (the
 * order every Valkey path takes). A result never moves a store out of a
 * status it left meanwhile: a late lock does not lock a store that is being
 * deleted.
 */
final class ValkeyCommandOutcomeService
{
    /** Result key of the store's network (CreateValkey). */
    private const RESULT_NETWORK = 'network';

    /** Result key of `{ host, port }` (CreateValkey). */
    private const RESULT_ENDPOINT = 'endpoint';

    /** Result key of whether ResizeValkey recreated the container. */
    private const RESULT_RESTARTED = 'restarted';

    /** Result key of the connections a rotation or lock closed. */
    private const RESULT_DISCONNECTED = 'disconnected_clients';

    /** Highest TCP port. */
    private const MAX_PORT = 65535;

    public function __construct(
        private readonly ValkeyCommandService $commands,
    ) {}

    public function completed(AgentNode $agent, AgentCommand $command): void
    {
        $valkey = $this->lockValkey($command);

        if ($valkey === null) {
            return;
        }

        $metadata = ['command_id' => $command->id];
        $result = is_array($command->result) ? $command->result : [];

        match ($command->type) {
            AgentCommandType::CreateValkey => $this->completeCreate($agent, $valkey, $result, $metadata),
            AgentCommandType::DeleteValkey => $this->completeDelete($agent, $valkey, $metadata),
            AgentCommandType::RotateValkeyPassword => $this->completeRotation($agent, $command, $valkey, [...$metadata, ...$this->disconnected($result)]),
            AgentCommandType::ResizeValkey => $this->completeResize($agent, $command, $valkey, $result, $metadata),
            AgentCommandType::LockValkey => $this->completeLock($agent, $command, $valkey, [...$metadata, ...$this->disconnected($result)]),
            AgentCommandType::UnlockValkey => $this->completeUnlock($agent, $valkey, $metadata),
            default => null,
        };
    }

    /**
     * A failed or expired command. `$actor` is the node that reported it, or
     * the expiry sweep. A failed delete, rotation, lock, or unlock changes no
     * status: the old password keeps working, and DELETE can be asked again.
     */
    public function failed(AgentNode|string $actor, AgentCommand $command, string $errorCode): void
    {
        $valkey = $this->lockValkey($command);

        if ($valkey === null) {
            return;
        }

        $metadata = ['command_id' => $command->id, 'error_code' => $errorCode];

        match ($command->type) {
            AgentCommandType::CreateValkey => $this->transition($valkey, ValkeyStatus::Provisioning, ValkeyStatus::Failed)
                && $this->audit($actor, ValkeyAudit::PROVISIONING_FAILED, $valkey, $metadata),
            AgentCommandType::DeleteValkey => $this->audit($actor, ValkeyAudit::DELETE_FAILED, $valkey, $metadata),
            AgentCommandType::RotateValkeyPassword => $this->failRotation($actor, $command, $valkey, $errorCode, $metadata),
            AgentCommandType::ResizeValkey => $this->failResize($actor, $command, $valkey, $metadata),
            AgentCommandType::LockValkey => $this->audit($actor, ValkeyAudit::LOCK_FAILED, $valkey, $metadata),
            AgentCommandType::UnlockValkey => $this->audit($actor, ValkeyAudit::UNLOCK_FAILED, $valkey, $metadata),
            default => null,
        };
    }

    /**
     * A first creation stores the network and address and makes the store
     * ready. A repeated one (valkeys:reconcile) on a ready or locked store
     * changes no status: CreateValkey never unlocks. A result without a
     * usable network or address fails a store that is still provisioning,
     * because no project could reach it.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $metadata
     */
    private function completeCreate(AgentNode $agent, ManagedValkey $valkey, array $result, array $metadata): bool
    {
        $endpoint = $this->endpointOf($valkey, $result);

        if ($endpoint === null) {
            $this->audit($agent, ValkeyAudit::INVALID_RESULT, $valkey, $metadata);

            return $this->transition($valkey, ValkeyStatus::Provisioning, ValkeyStatus::Failed)
                && $this->audit($agent, ValkeyAudit::PROVISIONING_FAILED, $valkey, [...$metadata, 'error_code' => ValkeyAudit::INVALID_RESULT]);
        }

        if ($valkey->trashed() || ! in_array($valkey->status, [ValkeyStatus::Provisioning, ...ValkeyStatus::provisionedCases()], true)) {
            return false;
        }

        $moved = $valkey->network !== $endpoint['network'] || $valkey->host !== $endpoint['host'] || $valkey->port !== $endpoint['port'];
        $first = $valkey->status === ValkeyStatus::Provisioning;

        $valkey->update([...$endpoint, ...($first ? ['status' => ValkeyStatus::Ready] : [])]);

        if ($moved && ! $first) {
            $this->refreshAttachedFingerprints($valkey);
        }

        return $this->audit($agent, $first ? ValkeyAudit::PROVISIONED : ValkeyAudit::RECONCILED, $valkey, [
            ...$metadata,
            'network' => $endpoint['network'],
            'host' => $endpoint['host'],
        ]);
    }

    /** @param  array<string, mixed>  $metadata */
    private function completeDelete(AgentNode $agent, ManagedValkey $valkey, array $metadata): bool
    {
        if ($valkey->trashed()) {
            return false;
        }

        $valkey->forceFill([
            'status' => ValkeyStatus::Deleted,
            'password' => null,
            'deleted_at' => now(),
        ])->save();

        $this->commands->eraseSecrets($valkey);

        return $this->audit($agent, ValkeyAudit::DELETED, $valkey, $metadata);
    }

    /**
     * The node now accepts the new password only and closed the old
     * connections: store it, and mark every attached project for a redeploy.
     * When the rotation asked for it, the redeploys are queued once this
     * transaction commits.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function completeRotation(AgentNode $agent, AgentCommand $command, ManagedValkey $valkey, array $metadata): bool
    {
        $password = $this->commands->passwordOf($command);

        if ($password === null || ! $valkey->status->isProvisioned()) {
            return false;
        }

        $valkey->update(['password' => $password]);
        $projects = $this->refreshAttachedFingerprints($valkey);

        if (CredentialRotation::redeploysAttached($command)) {
            RedeployAfterCredentialRotationJob::dispatch($command->id)->afterCommit();
        }

        return $this->audit($agent, ValkeyAudit::PASSWORD_ROTATED, $valkey, [
            ...$metadata,
            'project_ids' => $projects,
        ]);
    }

    /**
     * Like a bucket key rotation (BucketCommandOutcomeService::failRotation):
     * a first send that failed for sure keeps the old password; one whose
     * outcome the node could not confirm (the store was down, so the change
     * may apply when it starts, or the lease expired) is sent again with the
     * same password and a growing delay, until a send completes or
     * MAX_UNCERTAIN_ATTEMPTS is reached, and then ends `unknown`, never
     * `failed`. While it is uncertain, deployments and credentials answer
     * VALKEY_PASSWORD_ROTATING (ValkeyPasswordWindow).
     *
     * @param  array<string, mixed>  $metadata
     */
    private function failRotation(AgentNode|string $actor, AgentCommand $command, ManagedValkey $valkey, string $errorCode, array $metadata): bool
    {
        $attempt = CredentialRotation::attempt($command);
        $resendable = $attempt > 1
            ? ValkeyCommandFailure::resendableRotationCodes()
            : ValkeyCommandFailure::uncertainRotationCodes();
        $uncertain = $attempt > 1 || in_array($errorCode, ValkeyCommandFailure::uncertainRotationCodes(), true);

        if (in_array($errorCode, $resendable, true) && $valkey->status->isProvisioned() && $attempt < CredentialRotation::MAX_UNCERTAIN_ATTEMPTS) {
            $retry = $this->commands->resendRotation($valkey, $command);

            return $this->audit($actor, ValkeyAudit::PASSWORD_ROTATION_RESENT, $valkey, [
                ...$metadata,
                'resent_command_id' => $retry->id,
                'attempt' => $attempt + 1,
            ]);
        }

        return $this->audit($actor, ValkeyAudit::PASSWORD_ROTATION_FAILED, $valkey, [
            ...$metadata,
            'outcome_unknown' => $uncertain,
            'attempt' => $attempt,
        ]);
    }

    /**
     * The node applies the new size: it becomes both `maxmemory_mb` and the
     * reservation, which a decrease releases only now.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $metadata
     */
    private function completeResize(AgentNode $agent, AgentCommand $command, ManagedValkey $valkey, array $result, array $metadata): bool
    {
        $size = $this->commands->sizeOf($command);

        if ($size === null || $valkey->trashed()) {
            return false;
        }

        $from = $valkey->maxmemory_mb;
        $valkey->update(['maxmemory_mb' => $size, 'reserved_maxmemory_mb' => $size]);

        return $this->audit($agent, ValkeyAudit::RESIZED, $valkey, [
            ...$metadata,
            'from_mb' => $from,
            'to_mb' => $size,
            'restarted' => ($result[self::RESULT_RESTARTED] ?? null) === true,
        ]);
    }

    /**
     * The node kept the old size, so the reservation a growth took is
     * released.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function failResize(AgentNode|string $actor, AgentCommand $command, ManagedValkey $valkey, array $metadata): bool
    {
        if (! $valkey->trashed()) {
            $valkey->update(['reserved_maxmemory_mb' => $valkey->maxmemory_mb]);
        }

        return $this->audit($actor, ValkeyAudit::RESIZE_FAILED, $valkey, [
            ...$metadata,
            'maxmemory_mb' => $this->commands->sizeOf($command),
        ]);
    }

    /**
     * A lock records why it was asked for. A suspension lock that lands after
     * one of the attached projects was unsuspended again is lifted at once,
     * so a store is never left locked for a project that may run.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function completeLock(AgentNode $agent, AgentCommand $command, ManagedValkey $valkey, array $metadata): bool
    {
        $source = $this->commands->lockSourceOf($command);

        if ($valkey->status !== ValkeyStatus::Ready) {
            return false;
        }

        $valkey->update(['status' => ValkeyStatus::Locked, 'lock_source' => $source]);
        $this->audit($agent, ValkeyAudit::LOCKED, $valkey, [...$metadata, 'lock_source' => $source->value]);

        if ($source === ValkeyLockSource::Suspension && ! $this->everyAttachedProjectSuspended($valkey)) {
            $unlock = $this->commands->unlock($valkey);
            $this->audit(ValkeyAudit::SUSPENSION_ACTOR, ValkeyAudit::UNLOCK_REQUESTED, $valkey, ['command_id' => $unlock->id, 'lock_source' => $source->value]);
        }

        return true;
    }

    /** @param  array<string, mixed>  $metadata */
    private function completeUnlock(AgentNode $agent, ManagedValkey $valkey, array $metadata): bool
    {
        if ($valkey->status !== ValkeyStatus::Locked) {
            return false;
        }

        $valkey->update(['status' => ValkeyStatus::Ready, 'lock_source' => null]);

        return $this->audit($agent, ValkeyAudit::UNLOCKED, $valkey, $metadata);
    }

    /** Whether the store has attached projects and all of them are suspended. */
    public function everyAttachedProjectSuspended(ManagedValkey $valkey): bool
    {
        $statuses = $valkey->projects()->pluck('status');

        return $statuses->isNotEmpty() && $statuses->every(static fn (mixed $status): bool => ($status instanceof ProjectStatus ? $status : ProjectStatus::tryFrom((string) $status)) === ProjectStatus::Suspended);
    }

    /**
     * Recompute the fingerprint of every attached project, under their row
     * locks, so `redeploy_required` turns true.
     *
     * @return list<string> The projects' IDs.
     */
    private function refreshAttachedFingerprints(ManagedValkey $valkey): array
    {
        $projects = Project::query()
            ->whereIn('id', $valkey->attachments()->select('project_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($projects as $project) {
            $project->refreshValkeyFingerprint();
            $project->save();
        }

        /** @var list<string> */
        return $projects->modelKeys();
    }

    /**
     * The network and address CreateValkey reported, when the network is the
     * store's own and the address an IPv4 address with a port.
     *
     * @param  array<string, mixed>  $result
     * @return array{network: string, host: string, port: int}|null
     */
    private function endpointOf(ManagedValkey $valkey, array $result): ?array
    {
        $network = $result[self::RESULT_NETWORK] ?? null;
        $endpoint = $result[self::RESULT_ENDPOINT] ?? null;
        $host = is_array($endpoint) ? ($endpoint['host'] ?? null) : null;
        $port = is_array($endpoint) ? ($endpoint['port'] ?? null) : null;

        if ($network !== ManagedValkey::networkNameFor($valkey->id)
            || ! is_string($host)
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! is_int($port)
            || $port < 1
            || $port > self::MAX_PORT) {
            return null;
        }

        return ['network' => $network, 'host' => $host, 'port' => $port];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{disconnected_clients?: int}
     */
    private function disconnected(array $result): array
    {
        $count = $result[self::RESULT_DISCONNECTED] ?? null;

        return is_int($count) ? [self::RESULT_DISCONNECTED => $count] : [];
    }

    private function transition(ManagedValkey $valkey, ValkeyStatus $from, ValkeyStatus $to): bool
    {
        if ($valkey->status !== $from) {
            return false;
        }

        $valkey->update(['status' => $to]);

        return true;
    }

    /** @param  array<string, mixed>  $metadata */
    private function audit(AgentNode|string $actor, string $action, ManagedValkey $valkey, array $metadata): bool
    {
        ValkeyAudit::record($actor, $action, $valkey, $metadata);

        return true;
    }

    private function lockValkey(AgentCommand $command): ?ManagedValkey
    {
        if ($command->valkey_id === null) {
            return null;
        }

        return ManagedValkey::withTrashed()
            ->whereKey($command->valkey_id)
            ->lockForUpdate()
            ->first();
    }
}
