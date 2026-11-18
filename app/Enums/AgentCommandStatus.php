<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentCommandStatus: string
{
    case Pending = 'Pending';
    case Claimed = 'Claimed';
    case Running = 'Running';
    case Succeeded = 'Succeeded';
    case Failed = 'Failed';
    case Cancelled = 'Cancelled';
    case Expired = 'Expired';

    /** Whether the command may still run: queued, held by a node, or running. */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Pending,
            self::Claimed,
            self::Running => true,

            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function openCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => $status->isOpen(),
        ));
    }
}
