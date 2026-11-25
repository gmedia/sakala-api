<?php

declare(strict_types=1);

namespace App\Services\Valkey;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ValkeyLockSource;
use App\Models\AgentCommand;
use App\Models\ManagedValkey;
use App\Services\Agent\AgentCommandPayloadMaterializer;
use App\Support\Attachment\CredentialRotation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Creates the agent commands for one managed Valkey store (ADR-023). Every
 * command is pinned to the node that runs the store and waits for it without
 * a deadline. A password travels encrypted in `agent_commands.payload` and
 * is decrypted only on the wire to that node
 * (AgentCommandPayloadMaterializer). `project_id` and `deployment_id` are
 * null.
 *
 * Payloads (additive within protocol revision 4; sakala-agent
 * `examples/commands/valkey-commands.json`):
 *
 * - CreateValkey: `valkey_id`, `mode`, `maxmemory_mb`, `password`
 * - RotateValkeyPassword: `valkey_id`, `password`
 * - ResizeValkey: `valkey_id`, `maxmemory_mb`
 * - DeleteValkey, LockValkey, UnlockValkey: `valkey_id`
 */
final class ValkeyCommandService
{
    /** Payload key of the store ID. */
    public const VALKEY_ID_KEY = 'valkey_id';

    /** Payload key of the mode. */
    public const MODE_KEY = 'mode';

    /** Payload key of `maxmemory` in MB. */
    public const MAXMEMORY_KEY = 'maxmemory_mb';

    /** `request_context` key of why a LockValkey was asked for (ValkeyLockSource); never sent to the node. */
    public const LOCK_SOURCE_KEY = 'lock_source';

    public function create(ManagedValkey $valkey, string $password): AgentCommand
    {
        return $this->dispatch($valkey, AgentCommandType::CreateValkey, [
            self::VALKEY_ID_KEY => $valkey->id,
            self::MODE_KEY => $valkey->mode->value,
            self::MAXMEMORY_KEY => $valkey->maxmemory_mb,
            AgentCommandPayloadMaterializer::PASSWORD_KEY => Crypt::encryptString($password),
        ]);
    }

    public function delete(ManagedValkey $valkey): AgentCommand
    {
        return $this->dispatch($valkey, AgentCommandType::DeleteValkey, $this->identity($valkey));
    }

    /**
     * @param  array<string, mixed>|null  $requestContext  What the control plane does once the node reports
     *                                                     (CredentialRotation); never sent to the node.
     */
    public function rotatePassword(ManagedValkey $valkey, string $password, ?array $requestContext = null): AgentCommand
    {
        return $this->dispatch($valkey, AgentCommandType::RotateValkeyPassword, [
            ...$this->identity($valkey),
            AgentCommandPayloadMaterializer::PASSWORD_KEY => Crypt::encryptString($password),
        ], $requestContext);
    }

    /**
     * Send a RotateValkeyPassword again with the same new password, after an
     * outcome the node could not confirm: the agent's rotation is idempotent.
     * The control-plane context goes along with the next attempt number, and
     * the node sees it only after CredentialRotation::resendDelaySeconds().
     */
    public function resendRotation(ManagedValkey $valkey, AgentCommand $previous): AgentCommand
    {
        /** @var array<string, string|int> $payload */
        $payload = $previous->payload ?? [];
        $attempt = CredentialRotation::attempt($previous) + 1;

        return $this->dispatch($valkey, AgentCommandType::RotateValkeyPassword, $payload, [
            ...($previous->request_context ?? []),
            CredentialRotation::ATTEMPT_KEY => $attempt,
        ], now()->addSeconds(CredentialRotation::resendDelaySeconds($attempt)));
    }

    public function resize(ManagedValkey $valkey, int $maxmemoryMb): AgentCommand
    {
        return $this->dispatch($valkey, AgentCommandType::ResizeValkey, [
            ...$this->identity($valkey),
            self::MAXMEMORY_KEY => $maxmemoryMb,
        ]);
    }

    public function lock(ManagedValkey $valkey, ValkeyLockSource $source): AgentCommand
    {
        return $this->dispatch($valkey, AgentCommandType::LockValkey, $this->identity($valkey), [
            self::LOCK_SOURCE_KEY => $source->value,
        ]);
    }

    public function unlock(ManagedValkey $valkey): AgentCommand
    {
        return $this->dispatch($valkey, AgentCommandType::UnlockValkey, $this->identity($valkey));
    }

    /**
     * The password a CreateValkey or RotateValkeyPassword carries, or null for
     * another command, or one whose password was already erased.
     */
    public function passwordOf(AgentCommand $command): ?string
    {
        if (! $command->type->carriesValkeyPassword()) {
            return null;
        }

        $encrypted = $command->payload[AgentCommandPayloadMaterializer::PASSWORD_KEY] ?? null;

        return is_string($encrypted) ? Crypt::decryptString($encrypted) : null;
    }

    /** The size a ResizeValkey carries, or null for another command. */
    public function sizeOf(AgentCommand $command): ?int
    {
        $size = $command->payload[self::MAXMEMORY_KEY] ?? null;

        return $command->type === AgentCommandType::ResizeValkey && is_int($size) ? $size : null;
    }

    /** Why a LockValkey was asked for; an admin lock when it does not say. */
    public function lockSourceOf(AgentCommand $command): ValkeyLockSource
    {
        $source = $command->request_context[self::LOCK_SOURCE_KEY] ?? null;

        return (is_string($source) ? ValkeyLockSource::tryFrom($source) : null) ?? ValkeyLockSource::Admin;
    }

    /**
     * Remove every password copy from the store's commands, once the store is
     * deleted and none of them can run again.
     */
    public function eraseSecrets(ManagedValkey $valkey): void
    {
        $valkey->commands()
            ->whereIn('type', [AgentCommandType::CreateValkey, AgentCommandType::RotateValkeyPassword])
            ->get()
            ->each(static function (AgentCommand $command): void {
                $payload = $command->payload ?? [];
                unset($payload[AgentCommandPayloadMaterializer::PASSWORD_KEY]);
                $command->update(['payload' => $payload]);
            });
    }

    /** @return array{valkey_id: string} */
    private function identity(ManagedValkey $valkey): array
    {
        return [self::VALKEY_ID_KEY => $valkey->id];
    }

    /**
     * @param  array<string, string|int>  $payload
     * @param  array<string, mixed>|null  $requestContext
     */
    private function dispatch(ManagedValkey $valkey, AgentCommandType $type, array $payload, ?array $requestContext = null, ?CarbonInterface $availableAt = null): AgentCommand
    {
        return AgentCommand::query()->create([
            'valkey_id' => $valkey->id,
            'agent_node_id' => $valkey->node_id,
            'type' => $type,
            'status' => AgentCommandStatus::Pending,
            'payload' => $payload,
            'request_context' => $requestContext,
            'idempotency_key' => (string) Str::uuid(),
            'available_at' => $availableAt ?? now(),
            // Waits for the store's node however long it is offline: the
            // store cannot move to another node.
            'expires_at' => null,
        ]);
    }
}
