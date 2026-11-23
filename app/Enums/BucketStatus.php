<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a managed bucket (ADR-021). Agent command results move it;
 * the API never assumes a node did something it did not report.
 */
enum BucketStatus: string
{
    /** CreateBucket is waiting for or running on the storage node. */
    case Provisioning = 'provisioning';

    /** Created on the node; its key reads and writes. */
    case Ready = 'ready';

    /** CreateBucket failed or expired. Delete it and create another. */
    case Failed = 'failed';

    /** DeleteBucket is waiting for or running on the storage node. */
    case Deleting = 'deleting';

    /**
     * An admin locked the bucket (abuse): its key is disabled, so the
     * application cannot read or write, and presigned URLs stop working.
     * There is no quota lock: the node's hard quota refuses writes instead.
     */
    case Locked = 'locked';

    /** Deleted on the node. The row is soft deleted at the same moment. */
    case Deleted = 'deleted';

    /**
     * Statuses whose key exists on the node: what attached projects receive,
     * and what credentials, rotation, and quota changes accept.
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
     * Statuses that count toward `max_buckets` and the workspace's reserved
     * storage. A failed creation holds nothing on the node for the tenant, so
     * it keeps neither the slot nor the storage.
     *
     * @return list<self>
     */
    public static function countedCases(): array
    {
        return [self::Provisioning, self::Ready, self::Deleting, self::Locked];
    }
}
