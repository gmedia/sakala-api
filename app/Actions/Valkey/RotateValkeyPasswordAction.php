<?php

declare(strict_types=1);

namespace App\Actions\Valkey;

use App\Enums\AgentCommandType;
use App\Exceptions\Valkey\ValkeyBusyException;
use App\Exceptions\Valkey\ValkeyNotReadyException;
use App\Models\ManagedValkey;
use App\Models\User;
use App\Services\Valkey\ValkeyCommandService;
use App\Support\Attachment\CredentialRotation;
use App\Support\Valkey\ValkeyAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asks the Valkey node for a new password of the `default` user. The node
 * switches at once and closes the connections that are logged in, so
 * running applications fail from that moment until they are redeployed, as
 * a leaked password requires. The stored password stays until the node
 * reports success; then the new one is stored, every attached project shows
 * `redeploy_required`, and, when `$redeployAttached`, the attached projects
 * are redeployed (RedeployAfterCredentialRotationAction). Allowed while
 * locked, because a leaked password must be replaceable. Refused while a
 * CreateValkey is open: a repeated creation sets the password it carries.
 */
final class RotateValkeyPasswordAction
{
    public function __construct(
        private readonly ValkeyCommandService $commands,
    ) {}

    /**
     * @throws ValkeyNotReadyException
     * @throws ValkeyBusyException
     */
    public function handle(User $actor, ManagedValkey $valkey, bool $redeployAttached = false): ManagedValkey
    {
        return DB::transaction(function () use ($actor, $valkey, $redeployAttached): ManagedValkey {
            /** @var ManagedValkey $locked */
            $locked = ManagedValkey::query()->whereKey($valkey->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isProvisioned()) {
                throw new ValkeyNotReadyException($locked->status);
            }

            if ($locked->openCommand(AgentCommandType::RotateValkeyPassword) !== null) {
                throw ValkeyBusyException::rotationInProgress();
            }

            if ($locked->openCommand(AgentCommandType::CreateValkey) !== null) {
                throw ValkeyBusyException::creationInProgress();
            }

            $command = $this->commands->rotatePassword(
                $locked,
                Str::random(ManagedValkey::PASSWORD_LENGTH),
                CredentialRotation::requestContext($actor, $redeployAttached),
            );

            ValkeyAudit::record($actor, ValkeyAudit::PASSWORD_ROTATION_REQUESTED, $locked, [
                'command_id' => $command->id,
                'redeploy_attached' => $redeployAttached,
            ]);

            return $locked;
        });
    }
}
