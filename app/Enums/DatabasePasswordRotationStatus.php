<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the newest password rotation of a managed database stands, read from
 * its RotateDatabasePassword command.
 */
enum DatabasePasswordRotationStatus: string
{
    /** Waiting for or running on the database node. The stored password is still the old one. */
    case Pending = 'pending';

    /** The node switched to the new password: the old one no longer connects. */
    case Completed = 'completed';

    /** The node failed it or did not finish in time. The old password still works. */
    case Failed = 'failed';

    public static function fromCommandStatus(AgentCommandStatus $status): self
    {
        return match (true) {
            $status->isOpen() => self::Pending,
            $status === AgentCommandStatus::Succeeded => self::Completed,
            default => self::Failed,
        };
    }
}
