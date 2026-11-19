<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a managed database (ADR-020). Agent command results move it;
 * the API never assumes a node did something it did not report.
 */
enum DatabaseStatus: string
{
    /** CreateDatabase is waiting for or running on the database node. */
    case Provisioning = 'provisioning';

    /** Created on the node and accepting connections. */
    case Ready = 'ready';

    /** CreateDatabase failed or expired. Nothing to connect to; delete it and create another. */
    case Failed = 'failed';

    /** DropDatabase is waiting for or running on the database node. */
    case Deleting = 'deleting';

    /** Dropped on the node. The row is soft deleted at the same moment. */
    case Deleted = 'deleted';

    /**
     * The workspace reached its storage quota: the node turned the database's
     * role to NOLOGIN and ended its sessions. Superuser sessions, such as the
     * nightly backup, still connect.
     */
    case Locked = 'locked';

    /**
     * Statuses whose credentials work on the node: what the API injects into
     * attached projects and what credentials and rotation accept.
     *
     * @return list<self>
     */
    public static function provisionedCases(): array
    {
        return [self::Ready, self::Locked];
    }

    public function isProvisioned(): bool
    {
        return in_array($this, self::provisionedCases(), true);
    }

    /**
     * Statuses that count towards the workspace's database count. A failed
     * creation holds nothing on the node, so it does not keep the slot.
     *
     * @return list<self>
     */
    public static function countedCases(): array
    {
        return [self::Provisioning, self::Ready, self::Deleting, self::Locked];
    }
}
