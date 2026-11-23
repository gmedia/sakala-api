<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the newest key rotation of a managed bucket stands, read from its
 * RotateBucketKey command.
 */
enum BucketKeyRotationStatus: string
{
    /** Waiting for or running on the storage node. The stored secret is still the old one. */
    case Pending = 'pending';

    /** The node switched to the new secret: the old one no longer signs requests. */
    case Completed = 'completed';

    /** The node failed it definitively. The old secret still works. */
    case Failed = 'failed';

    /**
     * The node could not tell whether the new secret took effect, and the API
     * stopped resending it after CredentialRotation::MAX_UNCERTAIN_ATTEMPTS.
     * Neither secret is known to work; a new rotation settles it.
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
