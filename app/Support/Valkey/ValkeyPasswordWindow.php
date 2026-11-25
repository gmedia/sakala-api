<?php

declare(strict_types=1);

namespace App\Support\Valkey;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\ManagedValkey;
use App\Support\Attachment\CredentialRotation;

/**
 * The password uncertain window of a Valkey store, the counterpart of
 * BucketKeyWindow: while it is open, nobody can say whether the stored
 * password or the rotation's new one works, so no deployment receives
 * either and the owner cannot read it. It is open while the newest
 * RotateValkeyPassword
 *
 * - has been claimed by the node (it may switch at any moment), or
 * - is a resend (attempt > 1) that is still open, or
 * - ended `unknown`, until a later CreateValkey (`valkeys:reconcile`, which
 *   sets the stored password again) or a new rotation completes.
 *
 * A first attempt still pending and not claimed does not open it: the old
 * password certainly works, and an offline node must not block deploys.
 */
final class ValkeyPasswordWindow
{
    public static function isOpen(ManagedValkey $valkey): bool
    {
        $latest = self::latestRotation($valkey);

        if ($latest === null) {
            return false;
        }

        if ($latest->status->isOpen()) {
            return $latest->status !== AgentCommandStatus::Pending || CredentialRotation::attempt($latest) > 1;
        }

        return CredentialRotation::uncertain($latest) && ! self::settledAfter($valkey, $latest);
    }

    private static function latestRotation(ManagedValkey $valkey): ?AgentCommand
    {
        if ($valkey->relationLoaded('latestPasswordRotation')) {
            return $valkey->latestPasswordRotation;
        }

        return $valkey->latestPasswordRotation()->first();
    }

    /** A CreateValkey that completed after the unknown rotation set the stored password on the node again. */
    private static function settledAfter(ManagedValkey $valkey, AgentCommand $rotation): bool
    {
        return $valkey->commands()
            ->where('type', AgentCommandType::CreateValkey)
            ->where('status', AgentCommandStatus::Succeeded)
            ->where('created_at', '>=', $rotation->created_at)
            ->whereKeyNot($rotation->id)
            ->exists();
    }
}
