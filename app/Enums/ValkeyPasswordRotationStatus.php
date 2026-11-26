<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the newest password rotation of a managed Valkey store stands, read
 * from its RotateValkeyPassword command.
 */
enum ValkeyPasswordRotationStatus: string
{
    /** Waiting for or running on the Valkey node. The stored password is still the old one. */
    case Pending = 'pending';

    /** The node switched to the new password and closed the old connections. */
    case Completed = 'completed';

    /** The node failed it definitively. The old password still works. */
    case Failed = 'failed';

    /**
     * The node could not tell whether the new password took effect (the
     * store was down, so the change may apply when it starts), and the API
     * stopped resending it after CredentialRotation::MAX_UNCERTAIN_ATTEMPTS.
     * Neither password is known to work; a new rotation settles it.
     */
    case Unknown = 'unknown';

    public static function fromCommandStatus(AgentCommandStatus $status, bool $uncertain = false): self
    {
        return match (true) {
            $status->isOpen() => self::Pending,
            $status === AgentCommandStatus::Succeeded => self::Completed,
            $uncertain => self::Unknown,
            default => self::Failed,
        };
    }
}
