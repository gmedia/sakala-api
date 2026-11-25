<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a managed Valkey store (ADR-023). Agent command results move
 * it; the API never assumes a node did something it did not report.
 */
enum ValkeyStatus: string
{
    /** CreateValkey is waiting for or running on the Valkey node. */
    case Provisioning = 'provisioning';

    /** Created on the node; attached projects reach it from their next deployment. */
    case Ready = 'ready';

    /** CreateValkey failed or expired. Delete it and create another. */
    case Failed = 'failed';

    /** DeleteValkey is waiting for or running on the Valkey node. */
    case Deleting = 'deleting';

    /**
     * An admin locked the store (abuse, or every attached project is
     * suspended): its `default` user is disabled, so applications cannot log
     * in. The data stays.
     */
    case Locked = 'locked';

    /** Deleted on the node. The row is soft deleted at the same moment. */
    case Deleted = 'deleted';

    /**
     * Statuses whose store exists on the node: what attached projects
     * receive, and what credentials, rotation, and resizing accept.
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
     * Statuses that count toward `max_valkeys` and the workspace's Valkey
     * memory. A failed creation holds nothing for the tenant, so it keeps
     * neither the slot nor the memory.
     *
     * @return list<self>
     */
    public static function countedCases(): array
    {
        return [self::Provisioning, self::Ready, self::Deleting, self::Locked];
    }
}
